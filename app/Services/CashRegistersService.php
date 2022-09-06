<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Register;
use App\Models\RegisterHistory;
use Illuminate\Support\Facades\Auth;

class CashRegistersService
{
    public function openRegister( Register $register, $amount, $description )
    {
        if ( $register->status !== Register::STATUS_CLOSED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to open "%s" *, as it\'s not closed.' ),
                    $register->name
                )
            );
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_OPENING;
        $registerHistory->author = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->balance_before = $register->balance;
        $registerHistory->value = $amount;
        $registerHistory->balance_after = $register->balance + $amount;
        $registerHistory->save();

        $register->status = Register::STATUS_OPENED;
        $register->used_by = Auth::id();
        $register->balance = $amount;
        $register->save();

        return [
            'status' => 'success',
            'message' => __( 'The register has been successfully opened' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    public function closeRegister( Register $register, $amount, $description )
    {
        if ( $register->status !== Register::STATUS_OPENED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to open "%s" *, as it\'s not opened.' ),
                    $register->name
                )
            );
        }

        if ( (float) $register->balance === (float) $amount ) {
            $diffType = 'unchanged';
        } else {
            $diffType = $register->balance < (float) $amount ? 'positive' : 'negative';
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_CLOSING;
        $registerHistory->transaction_type = $diffType;
        $registerHistory->balance_after = abs( $register->balance - (float) $amount );
        $registerHistory->value = $amount;
        $registerHistory->balance_before = $register->balance;
        $registerHistory->author = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->save();

        $register->status = Register::STATUS_CLOSED;
        $register->used_by = null;
        $register->balance = 0;
        $register->save();

        return [
            'status' => 'success',
            'message' => __( 'The register has been successfully closed' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    public function cashIn( Register $register, $amount, $description )
    {
        if ( $register->status !== Register::STATUS_OPENED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to cashing on "%s" *, as it\'s not opened.' ),
                    $register->name
                )
            );
        }

        if ( $amount <= 0 ) {
            throw new NotAllowedException( __( 'The provided amount is not allowed. The amount should be greater than "0". ' ) );
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_CASHING;
        $registerHistory->author = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->balance_before = $register->balance;
        $registerHistory->value = $amount;
        $registerHistory->balance_after = $register->balance + $amount;
        $registerHistory->save();

        return [
            'status' => 'success',
            'message' => __( 'The cash has successfully been stored' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    public function saleDelete( Register $register, $amount, $description )
    {
        if ( $register->balance - $amount < 0 ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Not enough fund to delete a sale from "%s". If funds were cashed-out or disbursed, consider adding some cash (%s) to the register.' ),
                    $register->name,
                    trim( (string) ns()->currency->define( $amount ) )
                )
            );
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_DELETE;
        $registerHistory->author = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->balance_before = $register->balance;
        $registerHistory->value = $amount;
        $registerHistory->balance_after = $register->balance - $amount;
        $registerHistory->save();

        return [
            'status' => 'success',
            'message' => __( 'The cash has successfully been stored' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    public function cashOut( Register $register, $amount, $description )
    {
        if ( $register->status !== Register::STATUS_OPENED ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Unable to cashout on "%s", as it\'s not opened.' ),
                    $register->name
                )
            );
        }

        if ( $register->balance - $amount < 0 ) {
            throw new NotAllowedException(
                sprintf(
                    __( 'Not enough fund to cash out.' ),
                    $register->name
                )
            );
        }

        if ( $amount <= 0 ) {
            throw new NotAllowedException( __( 'The provided amount is not allowed. The amount should be greater than "0". ' ) );
        }

        $registerHistory = new RegisterHistory;
        $registerHistory->register_id = $register->id;
        $registerHistory->action = RegisterHistory::ACTION_CASHOUT;
        $registerHistory->author = Auth::id();
        $registerHistory->description = $description;
        $registerHistory->balance_before = $register->balance;
        $registerHistory->value = $amount;
        $registerHistory->balance_after = $register->balance - $amount;
        $registerHistory->save();

        return [
            'status' => 'success',
            'message' => __( 'The cash has successfully been disbursed.' ),
            'data' => [
                'register' => $register,
                'history' => $registerHistory,
            ],
        ];
    }

    /**
     * Will update the cash register balance using the
     * register history model.
     */
    public function updateRegisterBalance( RegisterHistory $registerHistory )
    {
        $register = Register::find( $registerHistory->register_id );

        if ( $register instanceof Register && $register->status === Register::STATUS_OPENED ) {
            if ( in_array( $registerHistory->action, RegisterHistory::IN_ACTIONS ) ) {
                $register->balance += $registerHistory->value;
            } elseif ( in_array( $registerHistory->action, RegisterHistory::OUT_ACTIONS ) ) {
                $register->balance -= $registerHistory->value;
            }

            $register->save();
        }
    }

    /**
     * Will increase the register balance if it's assigned
     * to the right store
     *
     * @param Order $order
     * @param OrderPayment $orderPayment.
     */
    public function increaseFromOrderPayment( Order $order, OrderPayment $orderPayment )
    {
        if ( $order->register_id !== null ) {
            $register = Register::find( $order->register_id );

            $registerHistory = new RegisterHistory;
            $registerHistory->balance_before = $register->balance;
            $registerHistory->value = $orderPayment->value;
            $registerHistory->balance_after = $register->balance + $orderPayment->value;
            $registerHistory->register_id = $register->id;
            $registerHistory->action = RegisterHistory::ACTION_SALE;
            $registerHistory->author = $order->author;
            $registerHistory->save();
        }
    }

    /**
     * Listen for payment status changes
     * that only occurs if the order is updated
     * and will update the register history accordingly.
     *
     * @return void
     */
    public function createRegisterHistoryUsingPaymentStatus( Order $order, string $previous, string $new  )
    {
        /**
         * If the payment status changed from
         * supported payment status to a "Paid" status.
         */
        if ( $order->register_id !== null && in_array( $previous, [
            Order::PAYMENT_DUE,
            Order::PAYMENT_HOLD,
            Order::PAYMENT_PARTIALLY,
            Order::PAYMENT_UNPAID,
        ] ) && $new === Order::PAYMENT_PAID ) {
            $register = Register::find( $order->register_id );

            $registerHistory = new RegisterHistory;
            $registerHistory->balance_before = $register->balance;
            $registerHistory->value = $order->total;
            $registerHistory->balance_after = $register->balance + $order->total;
            $registerHistory->register_id = $order->register_id;
            $registerHistory->action = RegisterHistory::ACTION_SALE;
            $registerHistory->author = Auth::id();
            $registerHistory->save();
        }
    }

    /**
     * Listen to order created and
     * will update the cash register if any order
     * is marked as paid.
     */
    public function createRegisterHistoryFromPaidOrder( Order $order )
    {
        /**
         * If the payment status changed from
         * supported payment status to a "Paid" status.
         */
        if ( $order->register_id !== null && $order->payment_status === Order::PAYMENT_PAID ) {
            $register = Register::find( $order->register_id );

            $registerHistory = new RegisterHistory;
            $registerHistory->balance_before = $register->balance;
            $registerHistory->value = $order->total;
            $registerHistory->balance_after = $register->balance + $order->total;
            $registerHistory->register_id = $order->register_id;
            $registerHistory->action = RegisterHistory::ACTION_SALE;
            $registerHistory->author = Auth::id();
            $registerHistory->save();
        }
    }

    /**
     * returns human readable labels
     * for all register actions.
     *
     * @param string $label
     * @return string
     */
    public function getActionLabel( $label )
    {
        switch ( $label ) {
            case RegisterHistory::ACTION_CASHING:
                return __( 'Cash In' );
            break;
            case RegisterHistory::ACTION_CASHOUT:
                return __( 'Cash Out' );
            break;
            case RegisterHistory::ACTION_CLOSING:
                return __( 'Closing' );
            break;
            case RegisterHistory::ACTION_OPENING:
                return __( 'Opening' );
            break;
            case RegisterHistory::ACTION_REFUND:
                return __( 'Refund' );
            break;
            case RegisterHistory::ACTION_SALE:
                return __( 'Sale' );
            break;
            default:
                return $label;
            break;
        }
    }

    /**
     * Returns the register status for human
     *
     * @param string $label
     * @return string
     */
    public function getRegisterStatusLabel( $label )
    {
        switch ( $label ) {
            case Register::STATUS_CLOSED:
                return __( 'Closed' );
            break;
            case Register::STATUS_DISABLED:
                return __( 'Disabled' );
            break;
            case Register::STATUS_INUSE:
                return __( 'In Use' );
            break;
            case Register::STATUS_OPENED:
                return __( 'Opened' );
            break;
            default:
                return $label;
            break;
        }
    }

    /**
     * Update the register with various details
     *
     * @param Register $register
     * @return void
     */
    public function getRegisterDetails( Register $register )
    {
        $register->status_label = $this->getRegisterStatusLabel( $register->status );
        $register->opening_balance = 0;
        $register->total_sale_amount = 0;

        if ( $register->status === Register::STATUS_OPENED ) {
            $history = $register->history()
                ->where( 'action', RegisterHistory::ACTION_OPENING )
                ->orderBy( 'id', 'desc' )->first();

            $register->opening_balance = $history->value;

            $register->total_sale_amount = Order::paid()
                ->where( 'register_id', $register->id )
                ->where( 'created_at', '>=', $history->created_at )
                ->sum( 'total' );
        }

        return $register;
    }
}
