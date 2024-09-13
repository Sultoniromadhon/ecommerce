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
    public function notification(Request $request)
    {
        $payload = $request->getContent();
        $notification = json_decode($payload);

        if (!$notification) {
            return response(['message' => 'Invalid JSON payload'], 400);
        }

        // Periksa apakah objek 'order_id' ada di payload
        if (!isset($notification->order_id)) {
            return response(['message' => 'Missing order_id in payload'], 400);
        }

        $validSignatureKey = hash("sha512", $notification->order_id . $notification->status_code . $notification->gross_amount . config('midtrans.serverKey'));

        if ($notification->signature_key != $validSignatureKey) {
            return response(['message' => 'Invalid signature'], 403);
        }

        $this->initPaymentGateway();
        $statusCode = null;

        $paymentNotification = new Notification();
        $order = Order::where('code', $paymentNotification->order_id)->firstOrFail();

        if ($order->isPaid()) {
            return response(['message' => 'The order has been paid before'], 422);
        }

        $transaction = $paymentNotification->transaction_status;
        $type = $paymentNotification->payment_type;
        $orderId = $paymentNotification->order_id;
        $fraud = $paymentNotification->fraud_status;

        $vaNumber = null;
        $vendorName = null;
        if (!empty($paymentNotification->va_numbers[0])) {
            $vaNumber = $paymentNotification->va_numbers[0]->va_number;
            $vendorName = $paymentNotification->va_numbers[0]->bank;
        }

        $paymentStatus = null;
        if ($transaction == 'capture') {
            // For credit card transaction, we need to check whether transaction is challenge by FDS or not
            if ($type == 'credit_card') {
                if ($fraud == 'challenge') {
                    // TODO set payment status in merchant's database to 'Challenge by FDS'
                    // TODO merchant should decide whether this transaction is authorized or not in MAP
                    $paymentStatus = Payment::CHALLENGE;
                } else {
                    // TODO set payment status in merchant's database to 'Success'
                    $paymentStatus = Payment::SUCCESS;
                }
            }
        } else if ($transaction == 'settlement') {
            // TODO set payment status in merchant's database to 'Settlement'
            $paymentStatus = Payment::SETTLEMENT;
        } else if ($transaction == 'pending') {
            // TODO set payment status in merchant's database to 'Pending'
            $paymentStatus = Payment::PENDING;
        } else if ($transaction == 'deny') {
            // TODO set payment status in merchant's database to 'Denied'
            $paymentStatus = Payment::DENY;
        } else if ($transaction == 'expire') {
            // TODO set payment status in merchant's database to 'expire'
            $paymentStatus = Payment::EXPIRE;
        } else if ($transaction == 'cancel') {
            // TODO set payment status in merchant's database to 'Denied'
            $paymentStatus = Payment::CANCEL;
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
            'va_number' => $vaNumber,
            'vendor_name' => $vendorName,
            'biller_code' => $paymentNotification->biller_code,
            'bill_key' => $paymentNotification->bill_key,
        ];

        $payment = Payment::create($paymentParams);

        if ($paymentStatus && $payment) {
            DB::transaction(
                function () use ($order, $payment) {
                    if (in_array($payment->status, [Payment::SUCCESS, Payment::SETTLEMENT])) {
                        $order->payment_status = Order::PAID;
                        $order->status = Order::CONFIRMED;
                        $order->save();
                    }
                }
            );
        }

        $message = 'Payment status is : ' . $paymentStatus;

        $response = [
            'code' => 200,
            'message' => $message,
        ];

        return response($response, 200);
    }

    // public function notification(Request $request)
    // {
    //     // Ambil payload dari request
    //     $payload = $request->getContent();
    //     $notification = json_decode($payload);

    //     // Validasi payload
    //     if (!$notification || !isset($notification->order_id) || !isset($notification->signature_key) || !isset($notification->status_code) || !isset($notification->gross_amount)) {
    //         return response(['message' => 'Invalid payload'], 400);
    //     }

    //     // Hitung validSignatureKey menggunakan order_id
    //     $validSignatureKey = hash("sha512", $notification->order_id . $notification->status_code . $notification->gross_amount . config('midtrans.serverKey'));

    //     // $signatureString = $order_id . $status_code . $gross_amount . $serverKey;
    //     // Validasi signature
    //     if ($notification->signature_key != $validSignatureKey) {
    //         return response(['message' => 'Invalid signature'], 403);
    //     }

    //     // Inisialisasi Payment Gateway
    //     $this->initPaymentGateway();

    //     // Inisialisasi Midtrans Notification
    //     $paymentNotification = new \Midtrans\Notification();

    //     // Cari order berdasarkan kode (order_id)
    //     $order = Order::where('code', $paymentNotification->order_id)->firstOrFail();

    //     // Jika order sudah dibayar, kirim respons bahwa transaksi sudah dilakukan
    //     if ($order->isPaid()) {
    //         \Log::info('Order ' . $order->id . ' has been paid before.');
    //         return response(
    //             ['message' => 'The order has been paid before'],
    //             422
    //         );
    //     }

    //     // Ambil status transaksi dan detail pembayaran
    //     $transaction = $paymentNotification->transaction_status;
    //     $type = $paymentNotification->payment_type;
    //     $fraud = $paymentNotification->fraud_status;

    //     // Ambil VA number jika ada
    //     $vaNumber = null;
    //     $vendorName = null;
    //     if (!empty($paymentNotification->va_numbers[0])) {
    //         $vaNumber = $paymentNotification->va_numbers[0]->va_number;
    //         $vendorName = $paymentNotification->va_numbers[0]->bank;
    //     }

    //     // Tentukan status pembayaran berdasarkan status transaksi
    //     // switch ($transaction) {
    //     //     case 'capture':
    //     //         if ($type == 'credit_card') {
    //     //             $paymentStatus = ($fraud == 'challenge') ? Payment::CHALLENGE : Payment::SUCCESS;
    //     //         }
    //     //         break;
    //     //     case 'settlement':
    //     //         $paymentStatus = Payment::SETTLEMENT;
    //     //         break;
    //     //     case 'pending':
    //     //         $paymentStatus = Payment::PENDING;
    //     //         break;
    //     //     case 'deny':
    //     //         $paymentStatus = Payment::DENY;
    //     //         break;
    //     //     case 'expire':
    //     //         $paymentStatus = Payment::EXPIRE;
    //     //         break;
    //     //     case 'cancel':
    //     //         $paymentStatus = Payment::CANCEL;
    //     //         break;
    //     //     default:
    //     //         \Log::warning('Unknown transaction status: ' . $transaction);
    //     //         return response(['message' => 'Unknown transaction status'], 400);
    //     // }

    //     // Buat parameter pembayaran
    //     $paymentParams = [
    //         'order_id' => $order->id,
    //         // 'number' => Payment::generateCode(),
    //         'amount' => $paymentNotification->gross_amount,
    //         // 'method' => 'midtrans',
    //         // 'status' => $paymentStatus,
    //         // 'token' => $paymentNotification->transaction_id,
    //         // 'payloads' => $payload,
    //         // 'payment_type' => $paymentNotification->payment_type,
    //         // 'va_number' => $vaNumber,
    //         // 'vendor_name' => $vendorName,
    //         // 'biller_code' => $paymentNotification->biller_code ?? null,
    //         // 'bill_key' => $paymentNotification->bill_key ?? null,
    //     ];

    //     // Simpan informasi pembayaran
    //     // try {
    //     //     $payment = Payment::create($paymentParams);
    //     // } catch (\Exception $e) {
    //     //     \Log::error('Failed to save payment: ' . $e->getMessage());
    //     //     return response(['message' => 'Payment could not be saved'], 500);
    //     // }

    //     // // Jika pembayaran berhasil disimpan, proses order
    //     // if (
    //     //     $paymentStatus && $payment
    //     // ) {
    //     //     \DB::transaction(function () use ($order, $payment) {
    //     //         if (in_array($payment->status, [Payment::SUCCESS, Payment::SETTLEMENT])) {
    //     //             $order->payment_status = Order::PAID;
    //     //             $order->status = Order::CONFIRMED;
    //     //             $order->save();
    //     //         }
    //     //     });
    //     // }

    //     // Kembalikan respons
    //     $message = 'Payment status is : ' . $paymentStatus;

    //     return response(['code' => 200, 'message' => $message], 200);
    // }
    // public function notification(Request $request)
    // {
    //     $payload = $request->getContent();
    //     $notification = json_decode($payload);

    //     $validSignatureKey = hash("sha512", $notification->order_id . $notification->status_code . $notification->gross_amount . config('midtrans.serverKey'));

    //     if ($notification->signature_key != $validSignatureKey) {
    //         return response(['message' => 'Invalid signature'], 403);
    //     }

    //     $this->initPaymentGateway();
    //     $statusCode = null;

    //     $paymentNotification = new \Midtrans\Notification();
    //     $order = Order::where('code', $paymentNotification->order_id)->firstOrFail();

    //     if ($order->isPaid()) {
    //         return response(['message' => 'The order has been paid before'], 422);
    //     }

    //     $transaction = $paymentNotification->transaction_status;
    //     $type = $paymentNotification->payment_type;
    //     $orderId = $paymentNotification->order_id;
    //     $fraud = $paymentNotification->fraud_status;

    //     $vaNumber = null;
    //     $vendorName = null;
    //     if (!empty($paymentNotification->va_numbers[0])) {
    //         $vaNumber = $paymentNotification->va_numbers[0]->va_number;
    //         $vendorName = $paymentNotification->va_numbers[0]->bank;
    //     }

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
    //         // TODO set payment status in merchant's database to 'Settlement'
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
    //         'amount' => $paymentNotification->gross_amount,
    //         'method' => 'midtrans',
    //         'status' => $paymentStatus,
    //         'token' => $paymentNotification->transaction_id,
    //         'payloads' => $payload,
    //         'payment_type' => $paymentNotification->payment_type,
    //         'va_number' => $vaNumber,
    //         'vendor_name' => $vendorName,
    //         'biller_code' => $paymentNotification->biller_code,
    //         'bill_key' => $paymentNotification->bill_key,
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
    //         'message' => $message,
    //     ];

    //     return response($response, 200);
    // }


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
        $order = Order::where('code', $code)->firstOrFail();

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
        $order = Order::where('code', $code)->firstOrFail();

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
        $order = Order::where('code', $code)->firstOrFail();

        // \Session::flash('error', "Sorry, we couldn't process your payment.");

        return redirect('orders/received/' . $order->id);
    }
}
