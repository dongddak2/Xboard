<?php

namespace App\Services;

use App\Jobs\ServerQuotaUsageJob;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;

class UserService
{
    private function calcResetDayByMonthFirstDay()
    {
        $today = date('d');
        $lastDay = date('d', strtotime('last day of +0 months'));
        return $lastDay - $today;
    }

    private function calcResetDayByExpireDay(int $expiredAt)
    {
        $day = date('d', $expiredAt);
        $today = date('d');
        $lastDay = date('d', strtotime('last day of +0 months'));
        if ((int) $day >= (int) $today && (int) $day >= (int) $lastDay) {
            return $lastDay - $today;
        }
        if ((int) $day >= (int) $today) {
            return $day - $today;
        }

        return $lastDay - $today + $day;
    }

    private function calcResetDayByYearFirstDay(): int
    {
        $nextYear = strtotime(date("Y-01-01", strtotime('+1 year')));
        return (int) (($nextYear - time()) / 86400);
    }

    private function calcResetDayByYearExpiredAt(int $expiredAt): int
    {
        $md = date('m-d', $expiredAt);
        $nowYear = strtotime(date("Y-{$md}"));
        $nextYear = strtotime('+1 year', $nowYear);
        if ($nowYear > time()) {
            return (int) (($nowYear - time()) / 86400);
        }
        return (int) (($nextYear - time()) / 86400);
    }

    public function getResetDay(User $user)
    {
        if (!isset($user->plan)) {
            $user->plan = Plan::find($user->plan_id);
        }
        if ($user->expired_at <= time() || $user->expired_at === NULL)
            return null;
        // ManduVPN NULL-PLAN-RESET-DAY: a user with no plan is never reset, so there is no reset day to
        // report. ResetTraffic groups by plan and then selects ->whereIn('plan_id', $planIds)
        // (ResetTraffic.php:48-58), so plan_id NULL matches no group and that traffic is never reset -
        // returning a date here would be a lie. null is the value this function already uses for "no reset
        // scheduled" (the expired branch above), and the app omits the row when it is null
        // (home_page.dart:856, account_page.dart:342), so no response field changes.
        // Without this guard every read below dereferences null: PHP warns, Laravel turns the warning into
        // an ErrorException, and /user/getSubscribe answers 500 (measured 5x) - as does the client config
        // path through ClientController:204. The guard sits here, in the shared function, because both
        // callers receive the same null.
        if ($user->plan === null)
            return null;
        // if reset method is not reset
        if ($user->plan->reset_traffic_method === 2)
            return null;
        switch (true) {
            case ($user->plan->reset_traffic_method === NULL): {
                $resetTrafficMethod = admin_setting('reset_traffic_method', 0);
                switch ((int) $resetTrafficMethod) {
                    // month first day
                    case 0:
                        return $this->calcResetDayByMonthFirstDay();
                    // expire day
                    case 1:
                        return $this->calcResetDayByExpireDay($user->expired_at);
                    // no action
                    case 2:
                        return null;
                    // year first day
                    case 3:
                        return $this->calcResetDayByYearFirstDay();
                    // year expire day
                    case 4:
                        return $this->calcResetDayByYearExpiredAt($user->expired_at);
                }
                break;
            }
            case ($user->plan->reset_traffic_method === 0): {
                return $this->calcResetDayByMonthFirstDay();
            }
            case ($user->plan->reset_traffic_method === 1): {
                return $this->calcResetDayByExpireDay($user->expired_at);
            }
            case ($user->plan->reset_traffic_method === 2): {
                return null;
            }
            case ($user->plan->reset_traffic_method === 3): {
                return $this->calcResetDayByYearFirstDay();
            }
            case ($user->plan->reset_traffic_method === 4): {
                return $this->calcResetDayByYearExpiredAt($user->expired_at);
            }
        }
        return null;
    }

    public function isAvailable(User $user)
    {
        if (!$user->banned && ($user->transfer_enable || MasterAccess::isUser($user)) && ($user->expired_at > time() || $user->expired_at === NULL)) {
            return true;
        }
        return false;
    }

    public function getAvailableUsers()
    {
        return User::where(fn ($q) => MasterAccess::includeUnlimitedTraffic($q))
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->get();
    }

    public function getUnAvailbaleUsers()
    {
        return User::where(function ($query) {
            $query->where('expired_at', '<', time())
                ->orWhere('expired_at', 0);
        })
            ->where(function ($query) {
                $query->where('plan_id', NULL)
                    ->orWhere('transfer_enable', 0);
            })
            ->get();
    }

    public function getUsersByIds($ids)
    {
        return User::whereIn('id', $ids)->get();
    }

    public function getAllUsers()
    {
        return User::all();
    }

    public function addBalance(int $userId, int $balance): bool
    {
        $user = User::lockForUpdate()->find($userId);
        if (!$user) {
            return false;
        }
        $user->balance = $user->balance + $balance;
        if ($user->balance < 0) {
            return false;
        }
        if (!$user->save()) {
            return false;
        }
        return true;
    }

    public function isNotCompleteOrderByUserId(int $userId): bool
    {
        $order = Order::whereIn('status', [0, 1])
            ->where('user_id', $userId)
            ->first();
        if (!$order) {
            return false;
        }
        return true;
    }

    public function trafficFetch(array $server, string $protocol, array $data)
    {
        list($server, $protocol, $data) = HookManager::filter('traffic.before_process', [
            $server,
            $protocol, 
            $data
        ]);

        $timestamp = strtotime(date('Y-m-d'));
        // ManduVPN SERVER-QUOTA-1: the instant THIS report arrived, captured here and carried into the
        // quota job, so a delayed or retried batch is attributed to the period it was REPORTED in rather
        // than the period it happened to run in. The three jobs below compute their own record date inside
        // handle() instead, which at a period boundary would hand out a free quota reset.
        $reportedAt = time();
        collect($data)->chunk(1000)->each(function ($chunk) use ($timestamp, $reportedAt, $server, $protocol) {
            TrafficFetchJob::dispatch($server, $chunk->toArray(), $protocol, $timestamp);
            StatUserJob::dispatch($server, $chunk->toArray(), $protocol, 'd');
            StatServerJob::dispatch($server, $chunk->toArray(), $protocol, 'd');
            // Records RAW bytes per (user, node, period) for the per-server cap - the one figure the three
            // jobs above cannot produce, because they all apply the plan's traffic rate.
            ServerQuotaUsageJob::dispatch($server, $chunk->toArray(), (string) $protocol, $reportedAt);
        });
    }
}
