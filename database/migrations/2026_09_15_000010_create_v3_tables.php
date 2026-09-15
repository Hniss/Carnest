<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 1 (MVP v3, D9) — tables des espaces référent / parent / administration :
 * consentement parental, cycle de vie des alertes, actions, suivis, messagerie
 * parent, synthèses parent, journal d'audit, délégation du référent,
 * notifications d'alerte (préparation lot 2) et notifications internes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_child', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->enum('relation', ['pere', 'mere', 'tuteur'])->default('tuteur');
            $table->boolean('consent_given')->default(false);
            $table->text('consent_text')->nullable();
            $table->timestamp('consent_timestamp')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('consent_withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['parent_id', 'child_id']);
        });

        Schema::create('alert_lifecycle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->enum('status', ['nouveau', 'qualifie', 'en_traitement', 'suivi', 'cloture']);
            $table->enum('qualification', ['pertinent', 'faux_positif', 'a_surveiller', 'confirme', 'urgent'])->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['alert_id', 'changed_at']);
        });

        Schema::create('alert_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->enum('action_type', ['entretien', 'contact_parent', 'surveillance', 'orientation_professionnel', 'autre']);
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['alert_id', 'performed_at']);
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('alert_id')->nullable()->constrained('alerts')->nullOnDelete();
            $table->enum('status', ['aucun', 'surveillance', 'accompagnement', 'intervention_urgente', 'termine'])->default('aucun');
            $table->date('next_review_date')->nullable();
            $table->text('objectif')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['child_id', 'next_review_date']);
        });

        Schema::create('parent_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->boolean('important')->default(false);
            $table->timestamps();
            $table->index(['school_id', 'updated_at']);
            $table->index(['parent_id', 'updated_at']);
        });

        Schema::create('parent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('parent_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('sender_role', ['parent', 'referent']);
            $table->text('body');
            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['thread_id', 'sent_at']);
        });

        Schema::create('parent_syntheses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('alert_id')->nullable()->constrained('alerts')->nullOnDelete();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('identified');
            $table->text('school_did');
            $table->text('school_proposes');
            $table->text('parent_can');
            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['parent_id', 'sent_at']);
            $table->index(['child_id', 'sent_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 20);
            $table->string('action', 60);
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['school_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        Schema::create('referent_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('referent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'delegate_id']);
        });

        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->enum('channel', ['app', 'email', 'sms'])->default('app');
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_step')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['alert_id', 'recipient_id']);
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'app_notifications', 'alert_notifications', 'referent_delegations', 'audit_logs',
            'parent_syntheses', 'parent_messages', 'parent_threads', 'follow_ups',
            'alert_actions', 'alert_lifecycle', 'parent_child',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
