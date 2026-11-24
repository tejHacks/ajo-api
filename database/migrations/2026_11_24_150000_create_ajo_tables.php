<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajo / esusu: a group contributes a fixed amount every cycle, and each cycle
 * one member (in payout order) collects the whole pot.
 *
 * Group and member ids are created on the phone, so groups made offline can be
 * synced later without clashing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajo_groups', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('amount');          // naira per member per cycle
            $table->enum('frequency', ['weekly', 'monthly']);
            $table->date('start_date');
            $table->timestamp('client_updated_at')->nullable(); // last change on a device, for last-write-wins
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ajo_members', function (Blueprint $table) {
            $table->id();
            $table->string('group_id', 64);
            $table->foreign('group_id')->references('id')->on('ajo_groups')->cascadeOnDelete();
            $table->string('member_key', 64);           // the id the phone uses
            $table->string('name', 120);
            $table->string('phone', 32)->default('');
            $table->unsignedSmallInteger('position');   // payout order: position i collects in cycle i
            $table->timestamps();
            $table->unique(['group_id', 'member_key']);
            $table->unique(['group_id', 'position']);
        });

        Schema::create('ajo_contributions', function (Blueprint $table) {
            $table->id();
            $table->string('group_id', 64);
            $table->foreign('group_id')->references('id')->on('ajo_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle');
            $table->string('member_key', 64);
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['group_id', 'cycle', 'member_key']);
        });

        Schema::create('ajo_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('group_id', 64);
            $table->foreign('group_id')->references('id')->on('ajo_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle');
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->unique(['group_id', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajo_payouts');
        Schema::dropIfExists('ajo_contributions');
        Schema::dropIfExists('ajo_members');
        Schema::dropIfExists('ajo_groups');
    }
};
