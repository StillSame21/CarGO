<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class PaymentTest extends TestCase
{
    // --- buildPaymentBreakdown() --------------------------------------------

    public function testBuildPaymentBreakdownSumsRentalAndLateFee(): void
    {
        $breakdown = buildPaymentBreakdown(1250.0, 500.0);

        $this->assertSame(1250.0, $breakdown['rental_subtotal']);
        $this->assertSame(500.0, $breakdown['late_fee_total']);
        $this->assertSame(1750.0, $breakdown['payable_total']);
    }

    public function testBuildPaymentBreakdownClampsNegativesToZero(): void
    {
        $breakdown = buildPaymentBreakdown(-10.0, -5.0);

        $this->assertSame(0.0, $breakdown['rental_subtotal']);
        $this->assertSame(0.0, $breakdown['late_fee_total']);
        $this->assertSame(0.0, $breakdown['payable_total']);
    }

    public function testBuildPaymentBreakdownRoundsToTwoDecimals(): void
    {
        $breakdown = buildPaymentBreakdown(10.005, 0.001);

        $this->assertSame(10.01, $breakdown['rental_subtotal']);
        $this->assertSame(0.0, $breakdown['late_fee_total']);
        $this->assertSame(10.01, $breakdown['payable_total']);
    }

    // --- calculatePaymentAmountDue() ----------------------------------------

    public function testCalculatePaymentAmountDueIsZeroWhenPaid(): void
    {
        $this->assertSame(0.0, calculatePaymentAmountDue(PAYMENT_STATUS_PAID, 1750.0));
    }

    public function testCalculatePaymentAmountDueReturnsStoredAmountWhenUnpaid(): void
    {
        $this->assertSame(500.0, calculatePaymentAmountDue(PAYMENT_STATUS_UNPAID, 500.0));
    }

    public function testCalculatePaymentAmountDueIsNullSafe(): void
    {
        $this->assertSame(0.0, calculatePaymentAmountDue(PAYMENT_STATUS_UNPAID, null));
        $this->assertSame(0.0, calculatePaymentAmountDue(null, null));
    }

    // --- calculateLateFeeAmount() -------------------------------------------

    public function testCalculateLateFeeAmountMultipliesDaysByRate(): void
    {
        $this->assertSame(500.0, calculateLateFeeAmount(2, 250.0));
    }

    public function testCalculateLateFeeAmountIsZeroForNoOrNegativeDays(): void
    {
        $this->assertSame(0.0, calculateLateFeeAmount(0, 250.0));
        $this->assertSame(0.0, calculateLateFeeAmount(-3, 250.0));
    }

    // --- bookingLateDays() ---------------------------------------------------

    public function testBookingLateDaysIsZeroOnTimeOrEarly(): void
    {
        $this->assertSame(0, bookingLateDays('2026-06-14', '2026-06-14'));
        $this->assertSame(0, bookingLateDays('2026-06-14', '2026-06-10'));
    }

    public function testBookingLateDaysCountsOverrun(): void
    {
        $this->assertSame(2, bookingLateDays('2026-06-14', '2026-06-16'));
    }

    public function testBookingLateDaysIsZeroForInvalidDates(): void
    {
        $this->assertSame(0, bookingLateDays('not-a-date', '2026-06-16'));
        $this->assertSame(0, bookingLateDays('2026-06-14', 'not-a-date'));
    }

    // --- bookingTotalDays() / bookingTotalAmount() ---------------------------

    public function testBookingTotalDaysIsInclusive(): void
    {
        $this->assertSame(1, bookingTotalDays('2026-06-14', '2026-06-14'));
        $this->assertSame(4, bookingTotalDays('2026-05-04', '2026-05-07'));
    }

    public function testBookingTotalAmountMultipliesDaysByDailyRate(): void
    {
        $this->assertSame(600.0, bookingTotalAmount(4, 150.0));
    }
}
