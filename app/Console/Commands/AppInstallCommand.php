<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;
use MCStreetguy\ComposerParser\Factory as ComposerParser;

class AppInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'at:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Amazing Time Basic Installation';

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * Create a new command instance.
     *
     * @param  Filesystem  $filesystem
     */
    public function __construct(
        Filesystem $filesystem
    ) {
        parent::__construct();
        $this->filesystem = $filesystem;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->filesystem->exists($this->laravel->environmentFilePath())) {
            $this->filesystem->copy(base_path('.env.example'), $this->laravel->environmentFilePath());
        }

        try {
            \DB::connection()->getPdo();

            if (Schema::hasTable('migrations')) {
                $this->error("Looks like the application was already installed. Please, make sure that database was flushed then try again");

                return -1;
            }
        } catch (\Exception $e) {
            // If we can't connect to the database that means that we're probably installing the app for the first time
        }


        $this->info("Welcome to CATTR installation wizard. First of, let's setup our application\n");
        $this->info("For now, we will setup the .env file configuration. You can change it later at any time.");
        $this->info("Let's connect to your database first");

        if ($this->settingUpDatabase() != 0) {
            return -1;
        }

        $this->info('Enter administrator credentials:');
        $adminData = $this->askAdminCredentials();

        if (!$this->registerInstance($adminData['login'])) {
            // User did not confirm installation
            $this->filesystem->delete(base_path('.env'));
            return -1;
        }

        $this->settingUpEnvMigrateAndSeed();

        $this->info("Creating admin user");
        $admin = $this->createAdminUser($adminData);
        $this->info("Administrator with email {$admin->email} was created successfully");

        $this->updateEnvData("RECAPTCHA_ENABLED", $this->choice("Enable RECaptcha", [
            "true" => "Yes",
            "false" => "No"
        ], 'false'));
        $this->call("config:cache");
        $this->info("Application was installed successfully!");
        return 0;
    }

    /**
     * Send information about the new instance on the server
     *
     * @param $adminEmail
     * @return bool
     */
    protected function registerInstance($adminEmail)
    {
        try {
            $client = new Client();

            $composerJson = ComposerParser::parse(base_path('composer.json'));
            $appVersion = $composerJson->getVersion();

            $response = $client->post('https://stats.cattr.app/v1/register', [
                'json' => [
                    'ownerEmail' => $adminEmail,
                    'version' => $appVersion
                ]
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (isset($responseBody['flashMessage'])) {
                $this->info($responseBody['flashMessage']);
            }

            if (isset($responseBody['updateVersion'])) {
                $this->alert("New version is available: {$responseBody['updateVersion']}");
            }

            if ($responseBody['knownVulnerable']) {
                return $this->confirm('You have a vulnerable version, are you sure you want to continue?');
            }

            return true;
        } catch (GuzzleException $e) {
            if ($e->getResponse()) {
                $error = json_decode($e->getResponse()->getBody(), true);
                $this->warn($error['message']);
            } else {
                $this->warn('Сould not get a response from the server to check the relevance of your version.');
            }

            return true;
        }
    }

    /**
     * @return User
     */
    protected function createAdminUser($admin): User
    {
        return User::create([
            'full_name' => $admin['name'],
            'email' => $admin['login'],
            'url' => '',
            'company_id' => 1,
            'payroll_access' => 1,
            'billing_access' => 1,
            'avatar' => '',
            'screenshots_active' => 1,
            'manual_time' => 0,
            'permanent_tasks' => 0,
            'computer_time_popup' => 300,
            'poor_time_popup' => '',
            'blur_screenshots' => 0,
            'web_and_app_monitoring' => 1,
            'webcam_shots' => 0,
            'screenshots_interval' => 9,
            'active' => true,
            'password' => $admin['password'],
            'is_admin' => true,
            'role_id' => 2,
        ]);
    }

    /**
     * @return array
     */
    protected function askAdminCredentials()
    {
        $login = $this->ask("Admin E-Mail");
        $password = Hash::make($this->secret("Admin ($login) Password"));
        $name = $this->ask("Admin Full Name");

        return [
            'login' => $login,
            'password' => $password,
            'name' => $name,
        ];
    }

    /**
     * @return void
     */
    protected function settingUpEnvMigrateAndSeed(): void
    {
        $this->updateEnvData("APP_URL", $this->ask("API endpoint FULL URL"));

        $this->updateEnvData("TRUSTED_FRONTEND_DOMAIN",
            '"'.$this->ask("Please provide trusted frontend domains (e.g cattr.mycompany.com). If you have multiple frontend domains, you can separate them with commas").'"');

        $this->info("Setting up JWT secret key");
        $this->call("jwt:secret");

        $this->info("Switching off debug mode...");
        $this->updateEnvData("APP_DEBUG", "false");

        $this->info("Running up migrations");
        $this->call("migrate");

        $this->info("Setting up default system roles");
        $this->call("db:seed", ['--class' => 'RoleSeeder']);
    }

    /**
     * @return int
     */
    protected function settingUpDatabase()
    {
        $this->updateEnvData(
            "DB_CONNECTION",
            $this->choice("Your database connection", array_keys(config("database.connections")), 0)
        );

        $this->updateEnvData("DB_HOST", $this->ask("CATTR database host", 'localhost'));
        $this->updateEnvData("DB_PORT", $this->ask("CATTR database port", 3306));
        $this->updateEnvData("DB_USERNAME", $this->ask("CATTR database username", 'root'));
        $this->updateEnvData("DB_DATABASE", $this->ask("CATTR database name", 'app_cattr'));
        $this->updateEnvData("DB_PASSWORD", $this->secret("CATTR database password"));

        $this->call('config:cache');

        $this->info("Testing database connection...");
        try {
            \DB::connection()->getPdo();

            if (Schema::hasTable('migrations')) {
                throw new \Exception("Looks like the application was already installed. Please, make sure that database was flushed and then try again.");
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return -1;
        }
        $this->info("Database testing successfully.\n");

        return 0;
    }

    /**
     * @param  string  $key
     * @param          $value
     *
     * @return void
     */
    protected function updateEnvData(string $key, $value): void
    {
        file_put_contents($this->laravel->environmentFilePath(), preg_replace(
            $this->replacementPattern($key, $value),
            $key.'='.$value,
            file_get_contents($this->laravel->environmentFilePath())
        ));
        Config::set($key, $value);
    }

    /**
     * Get a regex pattern that will match env APP_KEY with any random key.
     *
     * @param  string  $key
     * @param          $value
     *
     * @return string
     */
    protected function replacementPattern(string $key, $value): string
    {
        $escaped = preg_quote('='.env($key), '/');

        return "/^{$key}=.*/m";
    }
}
