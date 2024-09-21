<?php

namespace App\Http\Controllers\Frontend;

use App\Models\Order;
use App\Models\Payment;
use Midtrans\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{

    // $payload      = $request->getContent();
    // $notification = json_decode($payload);

    // $validSignatureKey = hash("sha512", $notification->order_id . $notification->status_code . $notification->gross_amount . config('services.midtrans.serverKey'));

    // if ($notification->signature_key != $validSignatureKey) {
    //     return response(['message' => 'Invalid signature'], 403);
    // }

    // $transaction  = $notification->transaction_status;
    // $type         = $notification->payment_type;
    // $orderId      = $notification->order_id;
    // $fraud        = $notification->fraud_status;
    //
    // public function notification(Request $request)
    // {
    //     $payload = $request->getContent();
    //     $notification = json_decode($payload);

    //     $validSignatureKey = hash("sha512", $notification->order_id . $notification->status_code . $notification->gross_amount . config('midtrans.serverKey'));

    //     if ($notification->signature_key != $validSignatureKey) {
    //         return response(['message' => 'Invalid signature'], 403);
    //     }

    //     $this->initPaymentGateway();


    //     // $paymentNotification = new Notification();
    //     // \Log::info('Midtrans Notification Payload:', (array) $notification);

    //     $order = Order::where('payment_token', $notification->transaction_id)->first();
    //     // // \Log::info('Midtrans Notification Payload2:', (array) $notification);

    //     if ($order->isPaid()) {
    //         return response(['message' => 'The order has been paid before'], 422);
    //     }

    //     $transaction = $notification->transaction_status;
    //     $type = $notification->payment_type;
    //     $orderId = $notification->order_id;
    //     $fraud = $notification->fraud_status;

    //     // $vaNumber = null;
    //     // $vendorName = null;
    //     // if (!empty($notification->va_numbers[0])) {
    //     //     $vaNumber = $notification->va_numbers[0]->va_number;
    //     //     $vendorName = $notification->va_numbers[0]->bank;
    //     // }

    //     $paymentStatus = null;
    //     if ($transaction == 'capture') {
    //         // For credit card transaction, we need to check whether transaction is challenge by FDS or not
    //         if ($type == 'credit_card') {
    //             if ($fraud == 'challenge') {
    //                 // TODO set payment status in merchant's database to 'Challenge by FDS'
    //                 // TODO merchant should decide whether this transaction is authorized or not in MAP
    //                 $paymentStatus = Payment::CHALLENGE;
    //             } else {
    //                 // TODO set payment status in merchant's database to 'Success'
    //                 $paymentStatus = Payment::SUCCESS;
    //             }
    //         }
    //     } else if ($transaction == 'settlement') {
    //         //     // TODO set payment status in merchant's database to 'Settlement'
    //         $paymentStatus = Payment::SETTLEMENT;
    //     } else if ($transaction == 'pending') {
    //         // TODO set payment status in merchant's database to 'Pending'
    //         $paymentStatus = Payment::PENDING;
    //     } else if ($transaction == 'deny') {
    //         // TODO set payment status in merchant's database to 'Denied'
    //         $paymentStatus = PAYMENT::DENY;
    //     } else if ($transaction == 'expire') {
    //         // TODO set payment status in merchant's database to 'expire'
    //         $paymentStatus = PAYMENT::EXPIRE;
    //     } else if ($transaction == 'cancel') {
    //         // TODO set payment status in merchant's database to 'Denied'
    //         $paymentStatus = PAYMENT::CANCEL;
    //     }

    //     $paymentParams = [
    //         'order_id' => $order->id,
    //         'number' => Payment::generateCode(),
    //         'amount' => $notification->gross_amount,
    //         'method' => 'midtrans',
    //         'status' => $paymentStatus,
    //         'token' => $notification->transaction_id,
    //         'payloads' => $payload,
    //         'payment_type' => $notification->payment_type,
    //         // 'va_number' => $vaNumber,
    //         // 'vendor_name' => $vendorName,
    //         // 'biller_code' => $notification->biller_code,
    //         // 'bill_key' => $notification->bill_key,
    //     ];

    //     $payment = Payment::create($paymentParams);

    //     if ($paymentStatus && $payment) {
    //         \DB::transaction(
    //             function () use ($order, $payment) {
    //                 if (in_array($payment->status, [Payment::SUCCESS, Payment::SETTLEMENT])) {
    //                     $order->payment_status = Order::PAID;
    //                     $order->status = Order::CONFIRMED;
    //                     $order->save();
    //                 }
    //             }
    //         );
    //     }

    //     $message = 'Payment status is : ' . $paymentStatus;

    //     $response = [
    //         'code' => 200,
    //         'data' => $validSignatureKey,
    //         'message' => $message,
    //     ];

    //     return response($response, 200);
    // }

    public function notification(Request $request)
    {

        Log::info('Midtrans Notification: Request received.');

        $payload = $request->getContent();
        Log::info('Midtrans Notification: Payload received.', ['payload' => $payload]);

        $notification = json_decode($payload);
        Log::info('Midtrans Notification: Payload decoded.', ['notification' => $notification]);

        $validSignatureKey = hash("sha512", $notification->order_id . $notification->status_code . $notification->gross_amount . config('midtrans.server_key'));
        Log::info('Midtrans Notification: Signature key generated.', ['validSignatureKey' => $validSignatureKey]);

        if (
            $notification->signature_key != $validSignatureKey
        ) {
            Log::warning('Midtrans Notification: Invalid signature.', ['signature_key' => $notification->signature_key]);
            return response(['message' => 'Invalid signature'], 403);
        }

        $this->initPaymentGateway();
        Log::info('Midtrans Notification: Payment gateway initialized.');

        $paymentNotification = new Notification();
        Log::info('Midtrans Notification: Notification object created.', ['paymentNotification' => $paymentNotification]);

        $order = Order::where('payment_token', $paymentNotification->transaction_id)->first();
        Log::info('Midtrans Notification: Order fetched.', ['order' => $order]);

        if ($order->isPaid()) {
            Log::info('Midtrans Notification: Order already paid.', ['order_id' => $order->id]);
            return response(['message' => 'The order has been paid before'], 422);
        }

        $transaction = $paymentNotification->transaction_status;
        $type = $paymentNotification->payment_type;
        $orderId = $paymentNotification->order_id;
        $fraud = $paymentNotification->fraud_status;

        Log::info('Midtrans Notification: Transaction details.', [
            'transaction' => $transaction,
            'type' => $type,
            'orderId' => $orderId,
            'fraud' => $fraud
        ]);

        $paymentStatus = null;
        if ($transaction == 'capture') {
            if ($type == 'credit_card') {
                if ($fraud == 'challenge') {
                    $paymentStatus = Payment::CHALLENGE;
                    Log::info('Midtrans Notification: Payment status set to CHALLENGE.');
                } else {
                    $paymentStatus = Payment::SUCCESS;
                    Log::info('Midtrans Notification: Payment status set to SUCCESS.');
                }
            }
        } else if ($transaction == 'settlement') {
            $paymentStatus = Payment::SETTLEMENT;
            Log::info('Midtrans Notification: Payment status set to SETTLEMENT.');
        } else if ($transaction == 'pending') {
            $paymentStatus = Payment::PENDING;
            Log::info('Midtrans Notification: Payment status set to PENDING.');
        } else if ($transaction == 'deny') {
            $paymentStatus = Payment::DENY;
            Log::info('Midtrans Notification: Payment status set to DENY.');
        } else if ($transaction == 'expire') {
            $paymentStatus = Payment::EXPIRE;
            Log::info('Midtrans Notification: Payment status set to EXPIRE.');
        } else if ($transaction == 'cancel') {
            $paymentStatus = Payment::CANCEL;
            Log::info('Midtrans Notification: Payment status set to CANCEL.');
        }

        $paymentParams = [
            'order_id' => $order->id,
            'number' => Payment::generateCode(),
            'amount' => $paymentNotification->gross_amount,
            'method' => 'midtrans',
            'status' => $paymentStatus,
            'token' => $paymentNotification->transaction_id,
            'payloads' => $payload,
            'payment_type' => $paymentNotification->payment_type,
        ];

        Log::info('Midtrans Notification: Payment parameters prepared.', ['paymentParams' => $paymentParams]);

        $payment = Payment::create($paymentParams);
        Log::info('Midtrans Notification: Payment record created.', ['payment' => $payment]);

        if ($paymentStatus && $payment) {
            DB::transaction(function () use ($order, $payment) {
                if (in_array($payment->status, [Payment::SUCCESS, Payment::SETTLEMENT])) {
                    $order->payment_status = Order::PAID;
                    $order->status = Order::CONFIRMED;
                    $order->save();
                    Log::info('Midtrans Notification: Order status updated to PAID and CONFIRMED.', ['order' => $order]);
                }
            });
        }

        Log::info('Midtrans Notification: Process completed successfully.');
        return response()->json(['status' => 'success']);
    }





    /**
     * Show completed payment status
     *
     * @param Request $request payment data
     *
     * @return void
     */
    public function completed(Request $request)
    {
        $code = $request->query('order_id');
        $order = Order::where('id', $code)->first();

        if ($order->payment_status == Order::UNPAID) {
            return redirect('payments/failed?order_id=' . $code);
        }

        // \Session::flash('success', "Thank you for completing the payment process!");

        return redirect('orders/received/' . $order->id);
    }

    /**
     * Show unfinish payment page
     *
     * @param Request $request payment data
     *
     * @return void
     */
    public function unfinish(Request $request)
    {
        $code = $request->query('order_id');
        $order = Order::where('code', $code)->first();

        // \Session::flash('error', "Sorry, we couldn't process your payment.");

        return redirect('orders/received/' . $order->id);
    }

    /**
     * Show failed payment page
     *
     * @param Request $request payment data
     *
     * @return void
     */
    public function failed(Request $request)
    {
        $code = $request->query('order_id');
        $order = Order::where('code', $code)->first();

        // \Session::flash('error', "Sorry, we couldn't process your payment.");

        return redirect('orders/received/' . $order->id);
    }
}
