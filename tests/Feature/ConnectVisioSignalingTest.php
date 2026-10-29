<?php

namespace Tests\Feature;

use App\Models\ConnectConversation;
use App\Models\User;
use Database\Seeders\ConnectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectVisioSignalingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create([
            'name' => 'Dr. Jean-Bernard OBAME NDONG',
            'email' => 'jb.obame@opn.ga',
            'password' => Hash::make('OPN2026@Libreville'),
            'code_role' => 'DG',
            'role' => 'Directeur Général',
            'entite' => 'Direction Générale OPN',
        ]);

        User::factory()->create([
            'name' => 'Mme Sylvie NTSAME ONDO',
            'email' => 's.ntsame@opn.ga',
            'password' => Hash::make('OPN2026@Owendo'),
            'code_role' => 'APPRO_NATIONAL',
            'role' => 'Directrice Appro',
            'entite' => 'Appro Owendo',
        ]);

        User::factory()->create([
            'name' => 'Mme Grâce NDZIÉ OBAME',
            'email' => 'g.ndzie@opn.ga',
            'password' => Hash::make('OPN2026@CollabAppro'),
            'code_role' => 'COLLABORATEUR_APPRO',
            'role' => 'Collaboratrice Appro',
            'entite' => 'Appro Owendo',
        ]);

        User::factory()->create([
            'name' => 'M. Jean OBIANG ESSONO',
            'email' => 'j.obiang@opn.ga',
            'password' => Hash::make('OPN2026@CollabAppro2'),
            'code_role' => 'COLLABORATEUR_APPRO',
            'role' => 'Collaborateur Appro',
            'entite' => 'Appro Owendo',
        ]);

        $this->seed(ConnectSeeder::class);
    }

    public function test_visio_start_clears_stale_signals_and_invite_flow(): void
    {
        $caller = User::where('email', 'jb.obame@opn.ga')->firstOrFail();
        $callee = User::where('email', 's.ntsame@opn.ga')->firstOrFail();

        $conversation = ConnectConversation::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $caller->id))
            ->whereHas('members', fn ($q) => $q->where('user_id', $callee->id))
            ->firstOrFail();

        $key = 'opn_visio_signals:'.$conversation->id;
        Cache::put($key, [
            [
                'id' => 'stale-1',
                'from_user_id' => $caller->id,
                'type' => 'offer',
                'payload' => ['sdp' => 'stale'],
                'created_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ], now()->addMinutes(30));

        Sanctum::actingAs($caller);

        $start = $this->postJson('/api/v1/connect/conversations/'.$conversation->id.'/visio/start', [
            'title' => 'Test Visio Bidirectionnelle',
        ])->assertCreated()
            ->assertJsonPath('success', true);

        $room = $start->json('data.room');
        $this->assertNotEmpty($room);
        $this->assertSame([], Cache::get($key, []));

        // Offer / answer / ice entre les deux membres
        $this->postJson('/api/v1/connect/conversations/'.$conversation->id.'/visio/signal', [
            'type' => 'join',
            'payload' => ['displayName' => $caller->name, 'role' => 'caller'],
        ])->assertCreated();

        $this->postJson('/api/v1/connect/conversations/'.$conversation->id.'/visio/signal', [
            'type' => 'offer',
            'payload' => [
                'sdp' => [
                    'type' => 'offer',
                    'sdp' => "v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n",
                ],
            ],
        ])->assertCreated();

        Sanctum::actingAs($callee);

        $signals = $this->getJson('/api/v1/connect/conversations/'.$conversation->id.'/visio/signals')
            ->assertOk()
            ->json('data');

        $types = collect($signals)->pluck('type')->all();
        $this->assertContains('join', $types);
        $this->assertContains('offer', $types);

        $this->postJson('/api/v1/connect/conversations/'.$conversation->id.'/visio/signal', [
            'type' => 'answer',
            'payload' => [
                'sdp' => [
                    'type' => 'answer',
                    'sdp' => "v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n",
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/v1/connect/conversations/'.$conversation->id.'/visio/signal', [
            'type' => 'ice',
            'payload' => [
                'candidate' => [
                    'candidate' => 'candidate:1 1 UDP 2122252543 127.0.0.1 54400 typ host',
                    'sdpMid' => '0',
                    'sdpMLineIndex' => 0,
                ],
            ],
        ])->assertCreated();

        Sanctum::actingAs($caller);
        $forCaller = $this->getJson('/api/v1/connect/conversations/'.$conversation->id.'/visio/signals')
            ->assertOk()
            ->json('data');

        $callerTypes = collect($forCaller)->pluck('type')->all();
        $this->assertContains('answer', $callerTypes);
        $this->assertContains('ice', $callerTypes);
        // Les signaux de l'appelant ne lui sont pas renvoyés
        $this->assertNotContains('offer', $callerTypes);

        Sanctum::actingAs($callee);
        $incoming = $this->getJson('/api/v1/connect/visio/incoming')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($incoming);
        $this->assertEquals($conversation->id, $incoming[0]['conversation_id']);
    }
}
