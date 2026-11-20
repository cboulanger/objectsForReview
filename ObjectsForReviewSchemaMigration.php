<?php

/**
 * @file ObjectsForReviewSchemaMigration.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewSchemaMigration
 * @brief Describe database table structures.
 *
 * Idempotent: also used to upgrade existing installations (see
 * ObjectsForReviewPlugin::upgradeSchema()).
 */

namespace APP\plugins\generic\objectsForReview;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class ObjectsForReviewSchemaMigration extends Migration
{
    /** Increase when tables or data migrations are added */
    const SCHEMA_VERSION = 4;

    /** Reservation fields that were stored with the object before schema version 2 */
    const LEGACY_RESERVATION_FIELDS = ['dateReserved', 'dueDate', 'copyPreference', 'shippingAddress', 'phone', 'copyNote', 'reminderSent'];

    /**
     * Run the migrations.
     */
    public function up()
    {
        if (!Schema::hasTable('objects_for_review')) {
            Schema::create('objects_for_review', function (Blueprint $table) {
                $table->bigInteger('object_id')->autoIncrement();
                $table->bigInteger('submission_id')->nullable();
                $table->bigInteger('context_id');
                $table->bigInteger('user_id')->nullable();
                $table->string('identifier', 255);
                $table->string('identifier_type', 255);
                $table->string('resource_type', 255);
                $table->string('creator', 255);
                $table->datetime('date_created');
            });
        }

        if (!Schema::hasTable('objects_for_review_settings')) {
            Schema::create('objects_for_review_settings', function (Blueprint $table) {
                $table->bigInteger('object_id');
                $table->string('locale', 14)->default('');
                $table->string('setting_name', 255);
                $table->longText('setting_value')->nullable();
                $table->string('setting_type', 6)->comment('(bool|int|float|string|object)');
                $table->index(['object_id'], 'objects_for_review_settings_id');
                $table->unique(['object_id', 'locale', 'setting_name'], 'objects_for_review_settings_pkey');
            });
        }

        // One row per bidder or reviewer of an object (see classes/Assignment.php)
        if (!Schema::hasTable('objects_for_review_assignments')) {
            Schema::create('objects_for_review_assignments', function (Blueprint $table) {
                $table->bigInteger('assignment_id')->autoIncrement();
                $table->bigInteger('object_id');
                $table->bigInteger('context_id');
                $table->bigInteger('user_id');
                $table->string('status', 32);
                $table->datetime('date_created');
                $table->datetime('date_status');
                $table->index(['object_id'], 'ofr_assignments_object_id');
                $table->index(['user_id'], 'ofr_assignments_user_id');
                $table->index(['context_id', 'status'], 'ofr_assignments_context_status');
            });
        }

        if (!Schema::hasTable('objects_for_review_assignment_settings')) {
            Schema::create('objects_for_review_assignment_settings', function (Blueprint $table) {
                $table->bigInteger('assignment_id');
                $table->string('locale', 14)->default('');
                $table->string('setting_name', 255);
                $table->longText('setting_value')->nullable();
                $table->string('setting_type', 6)->comment('(bool|int|float|string|object)');
                $table->index(['assignment_id'], 'ofr_assignment_settings_id');
                $table->unique(['assignment_id', 'locale', 'setting_name'], 'ofr_assignment_settings_pkey');
            });
        }

        // One-click links sent by email (see classes/ActionLink.php)
        if (!Schema::hasTable('objects_for_review_links')) {
            Schema::create('objects_for_review_links', function (Blueprint $table) {
                $table->bigInteger('link_id')->autoIncrement();
                $table->string('key_hash', 64);
                $table->bigInteger('assignment_id');
                $table->string('action', 32);
                $table->datetime('date_created');
                $table->datetime('date_expires');
                $table->datetime('date_used')->nullable();
                $table->unique(['key_hash'], 'ofr_links_key_hash');
                $table->index(['assignment_id'], 'ofr_links_assignment_id');
            });
        }

        $this->migrateReservations();
        $this->initCopyStatus();
        $this->plainTextTitles();
    }

    /**
     * Schema version 4: titles were entered in a rich text editor; they are plain text now.
     */
    protected function plainTextTitles(): void
    {
        $titles = DB::table('objects_for_review_settings')->where('setting_name', 'title')->get();
        foreach ($titles as $row) {
            $plain = trim(html_entity_decode(strip_tags((string) $row->setting_value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $plain = preg_replace('/\s+/u', ' ', $plain);
            if ($plain !== $row->setting_value) {
                DB::table('objects_for_review_settings')
                    ->where('object_id', $row->object_id)
                    ->where('locale', $row->locale)
                    ->where('setting_name', 'title')
                    ->update(['setting_value' => $plain]);
            }
        }
    }

    /**
     * Schema version 3: current reviewers get a review copy status (see CopyWorkflow).
     */
    protected function initCopyStatus(): void
    {
        $assignments = DB::table('objects_for_review_assignments')->where('status', 'accepted')->pluck('assignment_id');
        foreach ($assignments as $assignmentId) {
            $settings = DB::table('objects_for_review_assignment_settings')->where('assignment_id', $assignmentId);
            if ((clone $settings)->where('setting_name', 'copyStatus')->exists()) {
                continue;
            }
            $preference = (clone $settings)->where('setting_name', 'copyPreference')->value('setting_value');
            DB::table('objects_for_review_assignment_settings')->insert([
                'assignment_id' => $assignmentId,
                'locale' => '',
                'setting_name' => 'copyStatus',
                'setting_value' => in_array($preference, ['physical', 'electronic'], true) ? 'pending' : 'notNeeded',
                'setting_type' => 'string',
            ]);
        }
    }

    /**
     * Schema version 2: reservations stored with the object become assignments.
     * Objects with a reviewer and no submission become "accepted" assignments,
     * objects attached to a submission "submitted" ones.
     */
    protected function migrateReservations(): void
    {
        $objects = DB::table('objects_for_review')->whereNotNull('user_id')->get();
        foreach ($objects as $object) {
            $exists = DB::table('objects_for_review_assignments')
                ->where('object_id', $object->object_id)
                ->where('user_id', $object->user_id)
                ->exists();
            if ($exists) {
                continue;
            }
            $settings = DB::table('objects_for_review_settings')
                ->where('object_id', $object->object_id)
                ->whereIn('setting_name', self::LEGACY_RESERVATION_FIELDS)
                ->get();
            $dateReserved = $settings->firstWhere('setting_name', 'dateReserved')?->setting_value ?? $object->date_created;
            $now = date('Y-m-d H:i:s');
            $assignmentId = DB::table('objects_for_review_assignments')->insertGetId([
                'object_id' => $object->object_id,
                'context_id' => $object->context_id,
                'user_id' => $object->user_id,
                'status' => $object->submission_id ? 'submitted' : 'accepted',
                'date_created' => $dateReserved,
                'date_status' => $now,
            ], 'assignment_id');
            foreach ($settings as $setting) {
                DB::table('objects_for_review_assignment_settings')->insert([
                    'assignment_id' => $assignmentId,
                    'locale' => '',
                    'setting_name' => $setting->setting_name,
                    'setting_value' => $setting->setting_value,
                    'setting_type' => $setting->setting_type,
                ]);
            }
        }
        DB::table('objects_for_review_settings')
            ->whereIn('setting_name', self::LEGACY_RESERVATION_FIELDS)
            ->delete();
    }
}
