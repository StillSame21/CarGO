<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

class PricingEngine
{
    /**
     * Calculate dynamically adjusted price based on date range.
     * Weekend gets +15%.
     *
     * @param float $daily_rate
     * @param string $start_date (Y-m-d)
     * @param string $end_date (Y-m-d)
     * @return float
     */
    public static function calculatePrice($daily_rate, $start_date, $end_date)
    {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $total_price = 0.0;

        // Include the end date in the period
        $interval = new DateInterval('P1D');
        $end->add($interval);

        $period = new DatePeriod($start, $interval, $end);

        foreach ($period as $dt) {
            $dayOfWeek = $dt->format('N'); // 1 (Mon) - 7 (Sun)
            if ($dayOfWeek == 6 || $dayOfWeek == 7) {
                // Weekend +15%
                $total_price += $daily_rate * 1.15;
            } else {
                $total_price += $daily_rate;
            }
        }

        return round($total_price, 2);
    }
}
