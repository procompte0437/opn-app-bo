<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProduitController;
use App\Http\Controllers\Api\ApprovisionnementController;
use App\Http\Controllers\Api\UrgenceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\RealtimeStreamController;
use App\Http\Controllers\Api\StructureController;
use App\Http\Controllers\Api\AchatsController;
use App\Http\Controllers\Api\LogistiqueController;
use App\Http\Controllers\Api\HelpdeskController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\ConnectController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\ApproGouvernanceController;
use App\Http\Controllers\Api\ApproDocumentController;
use App\Http\Controllers\Api\ApproSuggestionDgController;
use App\Http\Controllers\Api\DirectionController;
use App\Http\Controllers\Api\DirectionModuleController;
use App\Http\Controllers\Api\NiveauAccesController;
use App\Http\Controllers\Api\FluxApprovisionnementController;
use App\Http\Controllers\Api\FluxCircuitController;
use App\Http\Controllers\Api\PatrimoineController;
use App\Http\Controllers\Api\OrganisationController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\ParametrageController;
use App\Http\Controllers\Api\SitePublicController;
use App\Http\Controllers\Api\CommercialController;

/*
|--------------------------------------------------------------------------
| OPN Gabon — API v1
|--------------------------------------------------------------------------
| Public: login, 2FA, newsletter subscribe, password-reset request.
| Everything else requires Sanctum Bearer token.
*/

Route::prefix('v1')->group(function () {

    // --- PUBLIC (no token) ---
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::post('/auth/verify-2fa', [AuthController::class, 'verify2fa'])->middleware('throttle:30,1');
    Route::post('/auth/confirm-totp', [AuthController::class, 'confirmTotp'])->middleware('throttle:30,1');
    Route::post('/auth/demo-quick', [AuthController::class, 'demoQuick'])->middleware('throttle:60,1');
    Route::get('/auth/demo-profils', [AuthController::class, 'demoProfils'])->middleware('throttle:60,1');
    Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:10,1');
    Route::get('/site', [SitePublicController::class, 'index'])->middleware('throttle:60,1');
    Route::post('/helpdesk/password-reset-request', [HelpdeskController::class, 'requestPasswordReset'])->middleware('throttle:5,1');

    // Snapshot JSON (polling multi-utilisateurs) — privilégié vs SSE
    Route::get('/realtime/snapshot', [RealtimeStreamController::class, 'snapshot'])->middleware('throttle:60,1');
    // SSE oneshot (déprécié en local)
    Route::get('/realtime/stream', [RealtimeStreamController::class, 'stream'])->middleware('throttle:20,1');

    // --- PROTECTED (Sanctum) ---
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Présence collègues (filtrée par province)
        Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat'])->middleware('throttle:60,1');
        Route::get('/presence/online', [PresenceController::class, 'online'])->middleware('throttle:60,1');
        Route::post('/presence/note', [PresenceController::class, 'sendNote'])->middleware('throttle:30,1');
        Route::post('/presence/file', [PresenceController::class, 'sendFile'])->middleware('throttle:20,1');

        // OPN Connect
        Route::get('/connect/directory', [ConnectController::class, 'directory']);
        Route::get('/connect/conversations', [ConnectController::class, 'index']);
        Route::post('/connect/conversations', [ConnectController::class, 'store']);
        Route::get('/connect/conversations/{id}', [ConnectController::class, 'show']);
        Route::post('/connect/conversations/{id}/members', [ConnectController::class, 'addMembers']);
        Route::delete('/connect/conversations/{id}/members/{userId}', [ConnectController::class, 'removeMember']);
        Route::get('/connect/conversations/{id}/messages', [ConnectController::class, 'messages']);
        Route::post('/connect/conversations/{id}/messages', [ConnectController::class, 'sendMessage']);
        Route::post('/connect/conversations/{id}/messages/audio', [ConnectController::class, 'sendAudio']);
        Route::post('/connect/conversations/{id}/read', [ConnectController::class, 'markRead']);
        Route::post('/connect/messages/{messageId}/reactions', [ConnectController::class, 'react'])->middleware('throttle:60,1');
        Route::get('/connect/conversations/{id}/jitsi', [ConnectController::class, 'jitsi']);
        Route::post('/connect/conversations/{id}/visio/start', [ConnectController::class, 'startVisio'])->middleware('throttle:30,1');
        Route::post('/connect/conversations/{id}/visio/signal', [ConnectController::class, 'visioSignal'])->middleware('throttle:120,1');
        Route::get('/connect/conversations/{id}/visio/signals', [ConnectController::class, 'visioSignals'])->middleware('throttle:120,1');
        Route::get('/connect/visio/incoming', [ConnectController::class, 'incomingVisio'])->middleware('throttle:60,1');

        Route::get('/dashboard/national', [DashboardController::class, 'getNationalOverview']);

        Route::get('/structures', [StructureController::class, 'index']);
        Route::get('/antennes', [StructureController::class, 'antennes']);
        Route::get('/structures/{id}', [StructureController::class, 'show']);
        Route::post('/structures', [StructureController::class, 'store']);

        Route::get('/pharmacy/produits', [ProduitController::class, 'index']);
        Route::get('/pharmacy/produits/{id}', [ProduitController::class, 'show']);

        Route::get('/supply/orders', [ApprovisionnementController::class, 'index']);
        Route::get('/supply/orders/{id}', [ApprovisionnementController::class, 'show']);
        Route::post('/supply/simulate-cmm', [ApprovisionnementController::class, 'simulateCmm']);
        Route::post('/supply/orders', [ApprovisionnementController::class, 'store']);
        Route::post('/supply/orders/{id}/validate-partial', [ApprovisionnementController::class, 'validatePartial']);
        Route::post('/supply/orders/{id}/instruct', [ApprovisionnementController::class, 'instruct']);
        Route::post('/supply/orders/{id}/validate-national', [ApprovisionnementController::class, 'validateNational']);
        Route::post('/supply/orders/{id}/transfer-achats', [ApprovisionnementController::class, 'transferToAchats']);
        Route::post('/supply/orders/{id}/allocate-fefo', [ApprovisionnementController::class, 'allocateFefo']);
        Route::post('/supply/orders/{id}/confirm-fefo', [ApprovisionnementController::class, 'confirmFefo']);
        Route::post('/supply/orders/{id}/launch-visas', [ApprovisionnementController::class, 'launchVisaCircuit']);
        Route::post('/supply/orders/{id}/visa', [ApprovisionnementController::class, 'submitVisa']);
        Route::post('/supply/orders/{id}/reject', [ApprovisionnementController::class, 'reject']);

        // Gouvernance Appro — tâches / contrôle / corrections
        Route::get('/supply/governance/today', [ApproGouvernanceController::class, 'todayQueue']);
        Route::get('/supply/governance/tasks', [ApproGouvernanceController::class, 'listTasks']);
        Route::post('/supply/governance/tasks', [ApproGouvernanceController::class, 'createTask']);
        Route::get('/supply/governance/collaborators', [ApproGouvernanceController::class, 'listCollaborators']);
        Route::get('/supply/governance/dossiers/{demandeId}/timeline', [ApproGouvernanceController::class, 'dossierTimeline']);
        Route::post('/supply/governance/tasks/{id}/start', [ApproGouvernanceController::class, 'startTask']);
        Route::post('/supply/governance/tasks/{id}/submit', [ApproGouvernanceController::class, 'submitForControl']);
        Route::post('/supply/governance/tasks/{id}/reassign', [ApproGouvernanceController::class, 'reassignTask']);
        Route::post('/supply/governance/tasks/{id}/control', [ApproGouvernanceController::class, 'controlDecision']);
        Route::post('/supply/governance/corrections/{id}/submit', [ApproGouvernanceController::class, 'submitCorrection']);

        // GED Appro — documents versionnés
        Route::get('/supply/documents', [ApproDocumentController::class, 'index']);
        Route::post('/supply/documents', [ApproDocumentController::class, 'upload']);
        Route::get('/supply/documents/{id}', [ApproDocumentController::class, 'show']);
        Route::get('/supply/documents/{id}/download', [ApproDocumentController::class, 'download']);
        Route::post('/supply/documents/{id}/versions', [ApproDocumentController::class, 'replaceVersion']);
        Route::post('/supply/documents/{id}/archive', [ApproDocumentController::class, 'archive']);

        // Suggestions au DG National
        Route::get('/supply/suggestions-dg', [ApproSuggestionDgController::class, 'index']);
        Route::post('/supply/suggestions-dg', [ApproSuggestionDgController::class, 'store']);
        Route::get('/supply/suggestions-dg/{id}', [ApproSuggestionDgController::class, 'show']);
        Route::put('/supply/suggestions-dg/{id}', [ApproSuggestionDgController::class, 'update']);
        Route::post('/supply/suggestions-dg/{id}/submit', [ApproSuggestionDgController::class, 'submit']);
        Route::post('/supply/suggestions-dg/{id}/decide', [ApproSuggestionDgController::class, 'dgDecide']);

        Route::get('/procurement/suppliers', [AchatsController::class, 'getSuppliers']);
        Route::get('/procurement/orders', [AchatsController::class, 'getPurchaseOrders']);
        Route::post('/procurement/orders', [AchatsController::class, 'createPurchaseOrder']);
        Route::post('/procurement/orders/{id}/workflow', [AchatsController::class, 'advanceWorkflow']);

        Route::get('/logistics/missions', [LogistiqueController::class, 'getMissions']);
        Route::post('/logistics/missions', [LogistiqueController::class, 'createMission']);
        Route::put('/logistics/missions/{id}/status', [LogistiqueController::class, 'updateMissionStatus']);
        Route::post('/logistics/missions/{id}/report', [LogistiqueController::class, 'submitMissionReport']);
        Route::post('/logistics/qr/verify', [LogistiqueController::class, 'verifyQrCode']);
        Route::post('/logistics/deliveries/{id}/sign-off', [LogistiqueController::class, 'signOffDelivery']);
        Route::post('/logistics/deliveries/{id}/close', [LogistiqueController::class, 'closeDeliveryWorkflow']);

        Route::get('/emergencies', [UrgenceController::class, 'index']);
        Route::post('/emergencies', [UrgenceController::class, 'store']);
        Route::put('/emergencies/{id}/resolve', [UrgenceController::class, 'resolve']);

        Route::get('/helpdesk/it-destination', [HelpdeskController::class, 'getItDestinationForUser']);

        Route::get('/flux/commandes', [FluxApprovisionnementController::class, 'index']);
        Route::post('/flux/commandes', [FluxApprovisionnementController::class, 'store']);
        Route::put('/flux/commandes/{id}', [FluxApprovisionnementController::class, 'modifier']);
        Route::post('/flux/commandes/{id}/transferer', [FluxApprovisionnementController::class, 'transferer']);
        Route::post('/flux/commandes/{id}/transmettre', [FluxApprovisionnementController::class, 'transmettre']);
        Route::post('/flux/commandes/{id}/disperser', [FluxApprovisionnementController::class, 'disperser']);
        Route::post('/flux/commandes/{id}/rejeter', [FluxApprovisionnementController::class, 'rejeter']);
        Route::delete('/flux/commandes/{id}', [FluxApprovisionnementController::class, 'supprimer']);
        Route::get('/flux/documents', [FluxApprovisionnementController::class, 'documents']);
        Route::get('/flux/documents/{id}/pdf', [FluxApprovisionnementController::class, 'documentPdf']);
        Route::get('/flux/documents/{id}', [FluxApprovisionnementController::class, 'document']);

        /* Circuit MAJ : brouillon, stock antenne, BC global, BL signé */
        Route::post('/flux/commandes/{id}/envoyer', [FluxCircuitController::class, 'envoyer']);
        Route::post('/flux/commandes/{id}/prise-antenne', [FluxCircuitController::class, 'priseAntenne']);
        Route::get('/flux/stock-antenne', [FluxCircuitController::class, 'stockAntenne']);
        Route::post('/flux/stock-antenne/entree', [FluxCircuitController::class, 'entreeStockAntenne']);
        Route::put('/flux/stock-antenne/{lotId}', [FluxCircuitController::class, 'ajusterStockAntenne']);
        Route::get('/flux/categories', [FluxCircuitController::class, 'categoriesProduits']);
        Route::get('/flux/bc-globaux', [FluxCircuitController::class, 'listerBcGlobaux']);
        Route::post('/flux/bc-globaux', [FluxCircuitController::class, 'creerBcGlobal']);
        Route::post('/flux/bc-globaux/{id}/transferer', [FluxCircuitController::class, 'transfererBcGlobal']);
        Route::post('/flux/bons-livraison', [FluxCircuitController::class, 'genererBl']);

        Route::get('/parametrage/territoire', [ParametrageController::class, 'territoire']);
        Route::get('/parametrage/utilisateurs', [ParametrageController::class, 'utilisateurs']);
        Route::post('/parametrage/utilisateurs', [ParametrageController::class, 'storeUtilisateur']);
        Route::put('/parametrage/utilisateurs/{id}', [ParametrageController::class, 'updateUtilisateur']);
        Route::delete('/parametrage/utilisateurs/{id}', [ParametrageController::class, 'desactiverUtilisateur']);
        Route::get('/parametrage/profils', [ParametrageController::class, 'profils']);
        Route::post('/parametrage/profils', [ParametrageController::class, 'storeProfil']);
        Route::put('/parametrage/profils/{id}', [ParametrageController::class, 'updateProfil']);
        Route::delete('/parametrage/profils/{id}', [ParametrageController::class, 'destroyProfil']);
        Route::get('/parametrage/habilitations', [ParametrageController::class, 'habilitations']);
        Route::post('/parametrage/habilitations', [ParametrageController::class, 'storeHabilitation']);
        Route::put('/parametrage/habilitations/{id}', [ParametrageController::class, 'updateHabilitation']);
        Route::get('/parametrage/habilitations-profils', [ParametrageController::class, 'liaisons']);
        Route::put('/parametrage/profils/{id}/habilitations', [ParametrageController::class, 'syncProfilHabilitations']);

        Route::get('/referentiel', [ReferentielController::class, 'ressources']);
        Route::post('/referentiel/medicaments/import', [ReferentielController::class, 'importMedicaments']);
        Route::get('/referentiel/{ressource}', [ReferentielController::class, 'index']);
        Route::post('/referentiel/{ressource}', [ReferentielController::class, 'store']);
        Route::put('/referentiel/{ressource}/{id}', [ReferentielController::class, 'update']);
        Route::delete('/referentiel/{ressource}/{id}', [ReferentielController::class, 'destroy']);

        Route::get('/organisation', [OrganisationController::class, 'index']);

        // Commercial / prospection vendeur
        Route::get('/commercial/referentiel', [CommercialController::class, 'referentielOptions']);
        Route::get('/commercial/prospects', [CommercialController::class, 'index']);
        Route::post('/commercial/prospects', [CommercialController::class, 'store']);
        Route::get('/commercial/prospects/{id}', [CommercialController::class, 'show']);
        Route::put('/commercial/prospects/{id}', [CommercialController::class, 'update']);
        Route::delete('/commercial/prospects/{id}', [CommercialController::class, 'destroy']);
        Route::post('/commercial/prospects/{id}/visites', [CommercialController::class, 'storeVisite']);
        Route::post('/commercial/prospects/{id}/convertir', [CommercialController::class, 'convertir']);

        Route::get('/patrimoine', [PatrimoineController::class, 'index']);
        Route::post('/patrimoine', [PatrimoineController::class, 'store']);
        Route::put('/patrimoine/{id}', [PatrimoineController::class, 'update']);
        Route::post('/patrimoine/{id}/remettre', [PatrimoineController::class, 'remettre']);

        Route::get('/audit/logs', [AuditController::class, 'index']);

        Route::get('/newsletter/subscribers', [NewsletterController::class, 'index']);
        Route::post('/newsletter/broadcast', [NewsletterController::class, 'broadcast']);

        // Directions et Modules
        Route::get('/directions', [DirectionController::class, 'index']);
        Route::get('/directions/{id}', [DirectionController::class, 'show']);
        Route::post('/directions', [DirectionController::class, 'store']);
        Route::put('/directions/{id}', [DirectionController::class, 'update']);
        Route::delete('/directions/{id}', [DirectionController::class, 'destroy']);

        Route::get('/directions/{directionId}/modules', [DirectionModuleController::class, 'index']);
        Route::get('/modules/{id}', [DirectionModuleController::class, 'show']);
        Route::post('/modules', [DirectionModuleController::class, 'store']);
        Route::put('/modules/{id}', [DirectionModuleController::class, 'update']);
        Route::delete('/modules/{id}', [DirectionModuleController::class, 'destroy']);

        Route::get('/niveaux-acces', [NiveauAccesController::class, 'index']);
        Route::get('/directions/{directionId}/niveaux-acces', [NiveauAccesController::class, 'byDirection']);
        Route::get('/niveaux-acces/{id}', [NiveauAccesController::class, 'show']);
        Route::post('/niveaux-acces', [NiveauAccesController::class, 'store']);
        Route::put('/niveaux-acces/{id}', [NiveauAccesController::class, 'update']);
        Route::delete('/niveaux-acces/{id}', [NiveauAccesController::class, 'destroy']);
        Route::post('/niveaux-acces/{niveauId}/modules', [NiveauAccesController::class, 'attachModule']);
        Route::delete('/niveaux-acces/{niveauId}/modules/{moduleId}', [NiveauAccesController::class, 'detachModule']);
    });
});
