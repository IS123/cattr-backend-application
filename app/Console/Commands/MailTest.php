<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test {destination}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test mail sending';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $destination = $this->argument('destination');

        Mail::raw('Text to e-mail', static function($message) use ($destination)
        {
            $message->to($destination);
        });
    }
}
