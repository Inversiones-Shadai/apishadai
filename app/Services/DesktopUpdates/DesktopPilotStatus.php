<?php

namespace App\Services\DesktopUpdates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DesktopPilotStatus
{
    /** Read-only server-console diagnostic. Never grants access or promotes a release. */
    public function snapshot(?string $product = null, string $architecture = 'x64', int $pendingMinutes = 30, bool $includeDevices = false): array
    {
        Validator::make(compact('product', 'architecture', 'pendingMinutes'), [
            'product' => ['nullable', 'in:'.implode(',', array_keys(config('desktop_updates.products')))],
            'architecture' => ['required', 'in:x64,x86,arm64'],
            'pendingMinutes' => ['required', 'integer', 'between:1,10080'],
        ])->validate();

        return DB::transaction(function () use ($product, $architecture, $pendingMinutes, $includeDevices): array {
            $now = CarbonImmutable::now('UTC');
            $tracks = DB::table('desktop_release_tracks')->where('architecture', $architecture)
                ->when($product !== null, fn ($query) => $query->where('product', $product))->orderBy('product')->get();
            $pilots = [];
            foreach ($tracks as $track) {
                $release = DB::table('desktop_releases')->where('id', $track->pilot_release_id)->first();
                $current = $release && $release->status === 'pilot' && $release->product === $track->product && $release->architecture === $architecture;
                $summary = array_fill_keys(['not_started', 'awaiting_report', 'overdue_report', 'installed_reported',
                    'failed_reported', 'ineligible', 'ambiguous_attempts', 'invalid_report'], 0);
                $devices = [];
                if ($current) {
                    $assigned = DB::table('desktop_release_pilot_devices as p')
                        ->leftJoin('desktop_license_devices as d', 'd.id', '=', 'p.activation_id')
                        ->leftJoin('desktop_licenses as l', 'l.id', '=', 'd.license_id')
                        ->where('p.release_id', $release->id)->orderBy('p.activation_id')
                        ->select(['p.activation_id', 'd.revoked_at', 'l.enabled', 'l.expires_at', 'l.product'])->get();
                    // Keep all ties at the newest authorization timestamp. UUID order is not time order.
                    $latest = DB::table('desktop_update_events as e')->where('e.release_id', $release->id)
                        ->where('e.product', $track->product)->where('e.architecture', $architecture)
                        ->where('e.action', 'updates.apply')->where('e.status', 'authorized')
                        ->whereIn('e.activation_id', $assigned->pluck('activation_id'))
                        ->whereNotExists(function ($query) use ($architecture, $track, $release): void {
                            $query->selectRaw('1')->from('desktop_update_events as newer')
                                ->whereColumn('newer.activation_id', 'e.activation_id')->where('newer.release_id', $release->id)
                                ->where('newer.product', $track->product)->where('newer.architecture', $architecture)
                                ->where('newer.action', 'updates.apply')->where('newer.status', 'authorized')
                                ->whereColumn('newer.created_at', '>', 'e.created_at');
                        })->select(['e.authorization_id', 'e.activation_id', 'e.release_revision', 'e.created_at'])->get();
                    $byDevice = $latest->groupBy('activation_id');
                    $reports = DB::table('desktop_update_installation_reports')->whereIn('authorization_id', $latest->pluck('authorization_id'))
                        ->select(['authorization_id', 'activation_id', 'release_id', 'release_revision', 'product', 'result', 'reported_version', 'created_at'])
                        ->get()->keyBy('authorization_id');
                    foreach ($assigned as $device) {
                        $eligible = $device->product === $track->product && (bool) $device->enabled && $device->revoked_at === null
                            && $device->expires_at !== null && CarbonImmutable::parse($device->expires_at, 'UTC')->greaterThan($now);
                        $attempts = $byDevice->get($device->activation_id, collect());
                        $state = 'not_started';
                        $observedVersion = null;
                        $reportedAt = null;
                        $elapsed = null;
                        if (! $eligible) {
                            $state = 'ineligible';
                        } elseif ($attempts->count() > 1) {
                            $state = 'ambiguous_attempts';
                        } elseif ($attempt = $attempts->first()) {
                            $report = $reports->get($attempt->authorization_id);
                            if ($report) {
                                $valid = $report->activation_id === $device->activation_id && $report->release_id === $release->id
                                    && $report->product === $track->product && (int) $report->release_revision === (int) $attempt->release_revision
                                    && in_array($report->result, ['installed', 'failed'], true)
                                    && ($report->result !== 'installed' || $report->reported_version === $release->version)
                                    && $report->created_at >= $attempt->created_at;
                                $state = $valid ? ($report->result === 'installed' ? 'installed_reported' : 'failed_reported') : 'invalid_report';
                                if ($valid) {
                                    $observedVersion = $report->reported_version;
                                    $reportedAt = CarbonImmutable::parse($report->created_at, 'UTC')->toIso8601String();
                                    $elapsed = (int) CarbonImmutable::parse($attempt->created_at, 'UTC')->diffInSeconds(CarbonImmutable::parse($report->created_at, 'UTC'));
                                }
                            } else {
                                $state = CarbonImmutable::parse($attempt->created_at, 'UTC')->lessThanOrEqualTo($now->subMinutes($pendingMinutes))
                                    ? 'overdue_report' : 'awaiting_report';
                            }
                        }
                        $summary[$state]++;
                        if ($includeDevices) {
                            $devices[] = ['activation_id' => $device->activation_id, 'state' => $state, 'reported_version' => $observedVersion,
                                'reported_at' => $reportedAt, 'result_received_after_seconds' => $elapsed];
                        }
                    }
                }
                $count = array_sum($summary);
                $entry = ['product' => $track->product, 'architecture' => $architecture,
                    'release_id' => $current ? $release->id : null, 'version' => $current ? $release->version : null,
                    'revision' => $current ? (int) $release->revision : null, 'current_pilot' => (bool) $current,
                    'assigned_devices' => $count, 'summary' => $summary,
                    'pilot_results_complete' => $current && $count > 0 && $summary['installed_reported'] === $count];
                if ($includeDevices) {
                    $entry['devices'] = $devices;
                }
                $pilots[] = $entry;
            }

            return ['schema_version' => 1, 'generated_at' => $now->toIso8601String(), 'pending_report_minutes' => $pendingMinutes,
                'scope' => 'current assigned pilots; last authorized apply attempt per activation', 'pilots' => $pilots,
                'production_readiness' => 'requires_environment_validation_and_operator_decision', 'writes_performed' => false];
        });
    }
}
