<?php

declare(strict_types=1);

namespace Fphp\Adaptive;

/**
 * Cybernetic PID (Proportional-Integral-Derivative) Homeostasis Controller
 *
 * Implements a closed-loop negative feedback control system inspired by aerospace
 * and cybernetics to dynamically regulate cache memory pressure, SWR resilience windows,
 * and compression tiers in real-time.
 *
 * Mathematical model:
 * u(t) = Kp * e(t) + Ki * ∫ e(τ)dτ + Kd * (de(t) / dt)
 *
 * Where:
 * - e(t) is the deviation between actual memory pressure and setpoint target.
 * - u(t) is the dynamic control signal that automatically expands grace windows
 *   during traffic surges and scales down overhead during calm operation.
 */
class PidController
{
    protected float $kp; // Proportional gain
    protected float $ki; // Integral gain
    protected float $kd; // Derivative gain

    protected float $setpoint;          // Desired target memory ratio (e.g. 0.70 = 70%)
    protected float $integral = 0.0;    // Accumulated error integral
    protected float $lastError = 0.0;   // Error at t - 1
    protected float $lastTime = 0.0;    // Timestamp of last sample
    protected float $lastOutput = 0.0;  // Last calculated control signal u(t)
    protected float $maxIntegral = 2.0; // Anti-windup clamp limit

    /**
     * @param float $kp Proportional gain (default 1.2)
     * @param float $ki Integral gain (default 0.05)
     * @param float $kd Derivative gain (default 0.25)
     * @param float $targetRatio Target memory utilization setpoint (0.10 to 0.95, default 0.70)
     */
    public function __construct(
        float $kp = 1.2,
        float $ki = 0.05,
        float $kd = 0.25,
        float $targetRatio = 0.70
    ) {
        $this->kp = $kp;
        $this->ki = $ki;
        $this->kd = $kd;
        $this->setpoint = max(0.10, min(0.95, $targetRatio));
        $this->lastTime = microtime(true);
    }

    /**
     * Parse system memory_limit in bytes.
     */
    public static function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            // Virtual limit assumed if unlimited (512 MB)
            return 512 * 1024 * 1024;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $val = (int)$limit;

        return match ($last) {
            'g' => $val * 1024 * 1024 * 1024,
            'm' => $val * 1024 * 1024,
            'k' => $val * 1024,
            default => $val
        };
    }

    /**
     * Measure current process memory utilization ratio (0.0 to 1.0+).
     */
    public function getCurrentMemoryRatio(): float
    {
        $used = memory_get_usage(true);
        $limit = self::getMemoryLimitBytes();

        return round($used / max(1, $limit), 4);
    }

    /**
     * Execute one discrete PID control iteration.
     *
     * @param float|null $measuredValue Optional custom process variable (if null, uses memory ratio)
     * @return float Control signal u(t)
     */
    public function sample(?float $measuredValue = null): float
    {
        $pv = $measuredValue ?? $this->getCurrentMemoryRatio();
        $now = microtime(true);
        $dt = max(0.001, $now - $this->lastTime);

        // Error: e(t) = ProcessVariable - Setpoint (positive means over target)
        $error = $pv - $this->setpoint;

        // Proportional term
        $p = $this->kp * $error;

        // Integral term with anti-windup clamping
        $this->integral += ($error * $dt);
        $this->integral = max(-$this->maxIntegral, min($this->maxIntegral, $this->integral));
        $i = $this->ki * $this->integral;

        // Derivative term with high-frequency noise filter and bounding
        $rawDerivative = ($dt >= 0.05) ? (($error - $this->lastError) / $dt) : ($error - $this->lastError);
        $d = max(-1.5, min(1.5, $this->kd * $rawDerivative));

        // Control Output u(t)
        $output = $p + $i + $d;

        // Update state
        $this->lastError = $error;
        $this->lastTime = $now;
        $this->lastOutput = round($output, 4);

        return $this->lastOutput;
    }

    /**
     * Dynamically adapt Stale-While-Revalidate (SWR) grace window based on system load.
     *
     * When control signal is high (memory/traffic surge), stretches grace window up to 4x
     * to protect backend databases from stampedes.
     *
     * @param int $baseGraceSeconds Base SWR grace period (e.g. 60s)
     * @param float|null $measuredValue Optional custom process variable (if null, uses last sampled state or memory ratio)
     * @return int Dynamically adapted grace period
     */
    public function adaptStaleWhileRevalidate(int $baseGraceSeconds = 60, ?float $measuredValue = null): int
    {
        $u = ($measuredValue !== null) ? $this->sample($measuredValue) : ($this->lastOutput !== 0.0 ? $this->lastOutput : $this->sample());
        if ($u <= 0.0) {
            return $baseGraceSeconds;
        }

        // Scale factor: 1.0 + min(4.0, u * 2.5)
        $scale = 1.0 + min(4.0, $u * 2.5);
        return (int)round($baseGraceSeconds * $scale);
    }

    /**
     * Dynamically adapt compression level based on system memory pressure.
     *
     * Under extreme memory strain, increases compression to conserve memory and I/O buffer.
     * Under low memory usage, relaxes compression to preserve CPU microseconds.
     *
     * @param int $baseLevel Default compression level (1-9)
     * @param float|null $measuredValue Optional custom process variable
     * @return int Adapted compression level (1-9)
     */
    public function adaptCompressionLevel(int $baseLevel = 6, ?float $measuredValue = null): int
    {
        $u = ($measuredValue !== null) ? $this->sample($measuredValue) : ($this->lastOutput !== 0.0 ? $this->lastOutput : $this->sample());
        if ($u > 0.3) {
            // Under memory strain, push towards high compression
            return min(9, $baseLevel + (int)ceil($u * 3));
        }

        if ($u < -0.3) {
            // Under plenty of headroom, drop compression for ultra-low latency
            return max(1, $baseLevel - 2);
        }

        return $baseLevel;
    }

    /**
     * Determine if preemptive LRU cache eviction should trigger.
     *
     * @param float|null $measuredValue Optional custom process variable
     */
    public function shouldPreemptivelyEvict(?float $measuredValue = null): bool
    {
        $pv = $measuredValue ?? $this->getCurrentMemoryRatio();
        $u = $this->sample($pv);

        // If memory is above 85% and control signal indicates elevated pressure
        return ($pv >= 0.85 && $u > 0.15);
    }

    /**
     * Get real-time cybernetic diagnostic telemetry.
     */
    public function getTelemetry(): array
    {
        $used = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = self::getMemoryLimitBytes();

        return [
            'setpoint' => $this->setpoint,
            'current_error' => round($this->lastError, 4),
            'control_signal_u' => $this->lastOutput,
            'integral_accumulator' => round($this->integral, 4),
            'memory_ratio' => round($used / max(1, $limit), 4),
            'memory_used_mb' => round($used / (1024 * 1024), 2),
            'memory_peak_mb' => round($peak / (1024 * 1024), 2),
            'memory_limit_mb' => round($limit / (1024 * 1024), 2),
            'proportional_gain' => $this->kp,
            'integral_gain' => $this->ki,
            'derivative_gain' => $this->kd
        ];
    }

    /**
     * Reset PID accumulator state.
     */
    public function reset(): void
    {
        $this->integral = 0.0;
        $this->lastError = 0.0;
        $this->lastTime = microtime(true);
        $this->lastOutput = 0.0;
    }
}
