<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\AuthModeService;
use App\Services\OpnAuthorizationService;
use Database\Seeders\ProvincesActeursSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    protected OpnAuthorizationService $authService;

    protected AuthModeService $authModeService;

    public function __construct(OpnAuthorizationService $authService, AuthModeService $authModeService)
    {
        $this->authService = $authService;
        $this->authModeService = $authModeService;
    }

    protected function formatUserPayload(User $user): array
    {
        $codeRole = $user->code_role ?? 'DEMANDEUR_STRUCTURE';
        $roleConfig = OpnAuthorizationService::ROLES_PERMISSIONS[$codeRole] ?? [
            'scope' => 'PROVINCE',
            'permissions' => ['demand.own.view'],
        ];

        $enforcedIt = $this->authService->getEnforcedItDestination($user);
        $user->loadMissing(['antenne.province', 'provinceRattachement', 'direction']);
        $antenneCompte = $user->antenne;
        if (!$antenneCompte && $user->province_id) {
            $antenneCompte = \App\Models\AntenneProvinciale::with('province')->find($user->province_id);
        }

        return [
            'id' => $user->id,
            'nom' => $user->name,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'code_role' => $user->code_role,
            'codeRole' => $user->code_role,
            'entite' => $user->entite,
            'province_id' => $user->province_id,
            'provinceId' => $user->province_id,
            'antenne_id' => $user->antenne_id ?: $user->province_id,
            'antenne' => $antenneCompte?->nom,
            'province_rattachement_id' => $user->province_rattachement_id ?: $antenneCompte?->province_id,
            'province_nom' => $user->provinceRattachement?->nom ?: $antenneCompte?->province?->nom,
            'direction_id' => $user->direction_id,
            'direction' => $user->direction?->nom,
            'structure_id' => $user->structure_id,
            'matricule_opn' => $user->matricule_opn,
            'service' => $user->service,
            'ville' => $user->ville,
            'telephone' => $user->telephone,
            'scope' => $roleConfig['scope'],
            'permissions' => $roleConfig['permissions'],
            'enforced_it_destination' => $enforcedIt,
            'etat' => $user->etat ?: 'actif',
            'auth_mode' => $this->authModeService->modeEffectif($user),
            'poste_comptable' => $user->poste_comptable,
            'profil' => $user->profil ? [
                'id' => $user->profil->id,
                'code' => $user->profil->code,
                'libelle' => $user->profil->libelle,
                'niveau_validation' => $user->profil->niveau_validation,
            ] : null,
            'habilitations' => $this->serialiserHabilitations($user),
        ];
    }

    protected function identiteSession(User $user): array
    {
        $payload = $this->formatUserPayload($user);

        return [
            'user' => $payload,
            'habilitations' => $payload['habilitations'],
        ];
    }

    protected function serialiserHabilitations(User $user): array
    {
        $user->loadMissing('profil.habilitations');
        if (!$user->profil) {
            return [];
        }

        return $user->profil->habilitations->map(function ($habilitation) {
            $creer = (bool) $habilitation->pivot->creer;
            $lire = (bool) $habilitation->pivot->lire;
            $modifier = (bool) $habilitation->pivot->modifier;
            $valider = (bool) $habilitation->pivot->valider;
            $supprimer = (bool) $habilitation->pivot->supprimer;
            return [
                'code' => $habilitation->code,
                'libelle' => $habilitation->libelle,
                'module' => $habilitation->module,
                'creer' => $creer,
                'lire' => $lire,
                'modifier' => $modifier,
                'valider' => $valider,
                'supprimer' => $supprimer,
                'acces' => $creer || $lire || $modifier || $valider || $supprimer,
            ];
        })->values()->all();
    }

    protected function compteInactif(User $user): bool
    {
        return ($user->etat ?? 'actif') === 'inactif';
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $rateKey = 'login:' . strtolower($validated['email']) . '|' . $request->ip();
        $maxAttempts = app()->environment('local') ? 30 : 5;
        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'success' => false,
                'message' => "Trop de tentatives. Reessayez dans {$seconds} secondes.",
            ], 429);
        }

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            try {
                if (ProvincesActeursSeeder::assurer($validated['email'], $validated['password'])) {
                    $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($validated['email'])])->first();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Identifiants invalides. Veuillez verifier votre email OPN ou mot de passe.',
            ], 401);
        }

        if ($this->compteInactif($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Ce compte est inactif.',
            ], 403);
        }

        RateLimiter::clear($rateKey);

        $mode = $this->authModeService->modeEffectif($user);

        if ($mode === AuthModeService::MODE_OTP_EMAIL) {
            $otpCode = $this->authModeService->genererEtEnvoyerOtpEmail($user);
            $payload = [
                'success' => true,
                'requires_2fa' => true,
                'auth_challenge' => AuthModeService::MODE_OTP_EMAIL,
                'email' => $user->email,
                'message' => 'Un code OTP a ete envoye a votre adresse e-mail.',
            ];
            if (app()->environment('local') || config('mail.default') === 'log') {
                $payload['dev_otp_hint'] = $otpCode;
            }

            return response()->json($payload);
        }

        if ($mode === AuthModeService::MODE_TOTP) {
            if (!$user->totp_confirmed_at) {
                return response()->json([
                    'success' => true,
                    'requires_totp_setup' => true,
                    'auth_challenge' => AuthModeService::MODE_TOTP,
                    'email' => $user->email,
                    'qr_svg' => $this->authModeService->qrSvg($user),
                    'totp_secret' => $user->fresh()->totp_secret,
                    'message' => 'Scannez le QR code avec Google Authenticator, puis saisissez le code genere.',
                ]);
            }

            return response()->json([
                'success' => true,
                'requires_2fa' => true,
                'auth_challenge' => AuthModeService::MODE_TOTP,
                'email' => $user->email,
                'message' => 'Saisissez le code Google Authenticator.',
            ]);
        }

        $token = $user->createToken('opn_auth_token')->plainTextToken;

        // Rehash local (coût réduit) après login réussi — accélère les connexions suivantes
        if (app()->environment('local') && Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($validated['password'])])->save();
        }

        AuditLog::create([
            'auteur_nom' => $user->name,
            'role' => $user->role ?? 'Agent OPN',
            'action' => 'CONNEXION_UTILISATEUR',
            'details' => "Connexion reussie a l'intranet OPN Hub ({$user->code_role}).",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            ...$this->identiteSession($user),
            'message' => 'Connexion etablie avec succes.',
        ]);
    }

    public function verify2fa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'auth_challenge' => 'nullable|in:otp_email,totp',
        ]);

        $rateKey = '2fa:' . strtolower($validated['email']) . '|' . $request->ip();
        $max2fa = app()->environment('local') ? 30 : 5;
        if (RateLimiter::tooManyAttempts($rateKey, $max2fa)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'success' => false,
                'message' => "Trop de tentatives 2FA. Reessayez dans {$seconds} secondes.",
            ], 429);
        }

        $user = User::where('email', $validated['email'])->first();
        if (!$user || $this->compteInactif($user)) {
            RateLimiter::hit($rateKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Code 2FA incorrect ou expire.',
            ], 422);
        }

        $mode = $validated['auth_challenge'] ?? $this->authModeService->modeEffectif($user);
        $otpValid = false;
        if ($mode === AuthModeService::MODE_TOTP) {
            $otpValid = $this->authModeService->totpValide($user, $validated['code']);
        } else {
            $otpValid = $this->authModeService->otpEmailValide($user, $validated['code']);
        }

        if (!$otpValid) {
            RateLimiter::hit($rateKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Code 2FA incorrect ou expire.',
            ], 422);
        }

        RateLimiter::clear($rateKey);

        $user->forceFill([
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ])->save();

        $token = $user->createToken('opn_auth_token_2fa')->plainTextToken;

        AuditLog::create([
            'auteur_nom' => $user->name,
            'role' => $user->role ?? 'Agent OPN',
            'action' => 'VALIDATION_2FA',
            'details' => "Authentification {$mode} validee pour {$user->code_role}.",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            ...$this->identiteSession($user),
            'message' => 'Session 2FA certifiee conforme.',
        ]);
    }

    /**
     * Première confirmation Google Authenticator (scan QR + code).
     */
    public function confirmTotp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'code' => 'required|string|size:6',
        ]);

        $rateKey = 'totp-setup:' . strtolower($validated['email']) . '|' . $request->ip();
        $maxAttempts = app()->environment('local') ? 30 : 5;
        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'success' => false,
                'message' => "Trop de tentatives. Reessayez dans {$seconds} secondes.",
            ], 429);
        }

        $user = User::where('email', $validated['email'])->first();
        if (!$user || !Hash::check($validated['password'], $user->password) || $this->compteInactif($user)) {
            RateLimiter::hit($rateKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Identifiants ou code invalides.',
            ], 422);
        }

        if ($this->authModeService->modeEffectif($user) !== AuthModeService::MODE_TOTP) {
            return response()->json([
                'success' => false,
                'message' => 'Google Authenticator n\'est pas active pour ce compte.',
            ], 422);
        }

        if (!$this->authModeService->confirmerTotp($user, $validated['code'])) {
            RateLimiter::hit($rateKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Code Google Authenticator incorrect.',
            ], 422);
        }

        RateLimiter::clear($rateKey);
        $token = $user->createToken('opn_auth_token_totp')->plainTextToken;

        AuditLog::create([
            'auteur_nom' => $user->name,
            'role' => $user->role ?? 'Agent OPN',
            'action' => 'ACTIVATION_TOTP',
            'details' => "Google Authenticator active et premiere connexion validee ({$user->code_role}).",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            ...$this->identiteSession($user->fresh()),
            'message' => 'Google Authenticator active. Session ouverte.',
        ]);
    }

    /**
     * Connexion démo 1 clic — LOCAL uniquement.
     * Pas de bcrypt / pas de 2FA : évite la saturation de php artisan serve.
     */
    public function demoQuick(Request $request): JsonResponse
    {
        if (! app()->environment('local')) {
            return response()->json([
                'success' => false,
                'message' => 'Connexion rapide démo indisponible hors environnement local.',
            ], 403);
        }

        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Profil démo introuvable. Vérifiez le seed DatabaseSeeder.',
            ], 404);
        }

        if ($this->compteInactif($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Ce compte est inactif.',
            ], 403);
        }

        // Création token seule (pas de delete préalable) — évite le lock SQLite sous charge
        $token = $user->createToken('opn_demo_quick')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            ...$this->identiteSession($user),
            'message' => 'Session démo ouverte (rapide).',
            'demo_quick' => true,
        ]);
    }

    /**
     * Liste publique des comptes actifs pour le modal de connexion (profils depuis la BDD).
     */
    public function demoProfils(): JsonResponse
    {
        $users = User::query()
            ->with(['profil:id,code,libelle', 'antenne:id,nom,chef_lieu,code_antenne,province_id', 'provinceRattachement:id,nom,code'])
            ->where(function ($q) {
                $q->whereNull('etat')->orWhere('etat', 'actif');
            })
            ->orderBy('name')
            ->get();

        $profils = $users->map(function (User $user) {
            $nom = $user->name ?: ($user->email ?: 'Utilisateur');
            // Rôle nominatif du compte (ex. province) en priorité — le libellé profil est générique.
            $role = $user->role ?: ($user->profil?->libelle ?: 'Agent OPN');
            $codeRole = $user->code_role ?: 'DEMANDEUR_STRUCTURE';
            $provinceNom = $user->provinceRattachement?->nom
                ?: $user->antenne?->nom
                ?: null;
            $provinceCode = $user->provinceRattachement?->code
                ?: (is_string($user->antenne?->code_antenne) ? substr($user->antenne->code_antenne, 0, 2) : null);
            $antenneNom = $user->antenne?->nom;

            return [
                'id' => $user->matricule_opn ?: ('USER-'.$user->id),
                'user_id' => $user->id,
                'nom' => $nom,
                'name' => $nom,
                'email' => $user->email,
                'role' => $role,
                'codeRole' => $codeRole,
                'code_role' => $codeRole,
                'profil_code' => $user->profil?->code,
                'profil' => $user->profil?->libelle,
                'provinceId' => $provinceCode,
                'province_nom' => $provinceNom,
                'antenne' => $antenneNom,
                'antenne_id' => $user->antenne_id,
                'province_id' => $user->province_id,
                'initiales' => $this->initialesDepuisNom($nom),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'profils' => $profils,
            'count' => $profils->count(),
        ]);
    }

    private function initialesDepuisNom(string $nom): string
    {
        $parts = preg_split('/\s+/', trim(preg_replace('/^(Ing\.|Dr\.|Mme|M\.|Capitaine|Inspecteur)\s+/iu', '', $nom) ?: $nom));
        $parts = array_values(array_filter($parts ?: []));
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
        }
        if (count($parts) === 1) {
            return strtoupper(mb_substr($parts[0], 0, 2));
        }

        return 'OPN';
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifie'], 401);
        }

        return response()->json([
            'success' => true,
            ...$this->identiteSession($user),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifie'], 401);
        }

        $actuel = $user->currentAccessToken();
        $nouveau = $user->createToken('opn_auth_token');
        $nouvelId = $nouveau->accessToken->id ?? null;
        if ($actuel && $actuel->id !== $nouvelId) {
            $actuel->delete();
        }

        return response()->json([
            'success' => true,
            'token' => $nouveau->plainTextToken,
            ...$this->identiteSession($user),
            'message' => 'Session prolongee.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->forceFill(['last_seen_at' => null])->save();
            $user->currentAccessToken()?->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Deconnexion effectuee avec succes.',
        ]);
    }
}
