<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMultiplyRoles extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_role', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('role_id')->index();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('role')->onDelete('cascade');

            $table->unique(['user_id', 'role_id']);
        });

        $users = \App\Models\User::query()->select(['id', 'role_id'])->get();
        $usersTotal = $users->count();
        echo "\n";
        foreach ($users as $index => $user) {
            /** @var \App\Models\User $user */
            $user->roles()->attach($user->role_id);
            echo "\rAttaching latests roles to users..." . ($index + 1) . "/" . $usersTotal . " (" . floor($usersTotal / ($index + 1) * 100.0) . "%)";
        }
        echo "\nAll roles attached to users!\n";

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_role_id_foreign');
            $table->dropColumn('role_id');
            $table->dropColumn('user_role_value');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('role_id')->default(1);
            $table->unsignedInteger('user_role_value')->default(1);
            $table->foreign('role_id')->references('id')->on('role');
        });

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('user_role');
        Schema::enableForeignKeyConstraints();
    }
}
