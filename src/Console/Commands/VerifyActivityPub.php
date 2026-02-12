<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\User;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Fieldtypes\ActorSelector;

class VerifyActivityPub extends Command
{
    protected $signature = 'verify:activitypub';
    protected $description = 'Verify ActivityPub Addon Logic';

    public function handle(): int
    {
        $this->info('Starting verification...');

        // 1. Create Actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('actor-1')
            ->data([
                'title' => 'Actor 1',
                'handle' => '@actor1',
            ]);
        $actor->save();
        $this->info('Actor created: ' . $actor->id());

        // 2. Create User and link Actor
        $user = User::make()
            ->email('test@example.com')
            ->data([
                'name' => 'Test User',
                'actors' => [$actor->id()],
            ]);
        $user->save();
        $this->info('User created with actor link.');

        // 3. Simulate acting as user
        auth()->login($user);

        // 4. Check ActorSelector default value
        $fieldtype = new ActorSelector();
        // We need to set the field context if necessary, but defaultValue() uses User::current()

        $default = $fieldtype->defaultValue();

        if ($default === $actor->id()) {
            $this->info('SUCCESS: Default value matches Actor ID.');
        } else {
            $this->error('FAILURE: Default value is ' . json_encode($default) . ', expected ' . $actor->id());
        }

        // Cleanup
        $user->delete();
        $actor->delete();
        return 0;
    }
}
