<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation - {{ $planDetails['name'] }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 10px;
        }
        .success-title {
            font-size: 28px;
            color: #28a745;
            margin-bottom: 10px;
        }
        .success-subtitle {
            color: #666;
            font-size: 16px;
        }
        .success-icon {
            font-size: 48px;
            color: #28a745;
            margin-bottom: 20px;
        }
        .payment-details {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .payment-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .payment-row:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 18px;
            color: #28a745;
        }
        .plan-details {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .plan-name {
            font-size: 20px;
            font-weight: bold;
            color: #155724;
            margin-bottom: 10px;
        }
        .plan-price {
            font-size: 24px;
            font-weight: bold;
            color: #28a745;
        }
        .cta-button {
            display: inline-block;
            background-color: #28a745;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
        }
        .cta-button:hover {
            background-color: #218838;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #666;
            font-size: 14px;
        }
        .contact-info {
            margin-top: 15px;
            font-size: 12px;
            color: #999;
        }
        .features-list {
            background-color: #e8f5e8;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }
        .features-list h3 {
            color: #155724;
            margin-bottom: 10px;
        }
        .features-list ul {
            margin: 0;
            padding-left: 20px;
        }
        .features-list li {
            margin-bottom: 5px;
            color: #155724;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="logo">{{ config('app.name', 'Qricket') }}</div>
            <div class="success-icon">✓</div>
            <div class="success-title">Payment Successful!</div>
            <div class="success-subtitle">Your subscription has been activated</div>
        </div>

        <p>Dear {{ $user->name }},</p>

        <p>Great news! Your payment has been successfully processed and your subscription is now active. Thank you for choosing our service!</p>

        <div class="plan-details">
            <div class="plan-name">{{ $planDetails['name'] }}</div>
            <div class="plan-price">₱{{ number_format($planDetails['price'], 2) }}</div>
        </div>

        <div class="payment-details">
            <div class="payment-row">
                <span>Transaction ID:</span>
                <span>{{ $subscription->xendit_payment_id }}</span>
            </div>
            <div class="payment-row">
                <span>Invoice ID:</span>
                <span>{{ $subscription->xendit_invoice_id }}</span>
            </div>
            <div class="payment-row">
                <span>Payment Date:</span>
                <span>{{ $subscription->updated_at->format('M d, Y H:i') }}</span>
            </div>
            <div class="payment-row">
                <span>Status:</span>
                <span style="color: #28a745; font-weight: bold;">Paid</span>
            </div>
            <div class="payment-row">
                <span>Amount Paid:</span>
                <span>₱{{ number_format($subscription->amount, 2) }}</span>
            </div>
        </div>

        <div class="features-list">
            <h3>Your Subscription Includes:</h3>
            <ul>
                @if($planDetails['id'] === 'basic')
                    <li>Basic features</li>
                    <li>Email support</li>
                    <li>1 user</li>
                @elseif($planDetails['id'] === 'pro')
                    <li>All Basic features</li>
                    <li>Priority support</li>
                    <li>5 users</li>
                    <li>Advanced features</li>
                @elseif($planDetails['id'] === 'enterprise')
                    <li>All Pro features</li>
                    <li>24/7 support</li>
                    <li>Unlimited users</li>
                    <li>Custom features</li>
                @endif
            </ul>
        </div>

        <p><strong>Subscription Period:</strong><br>
        {{ $subscription->start_date->format('M d, Y') }} to {{ $subscription->end_date->format('M d, Y') }}</p>

        <p>You can now access all the features included in your subscription. If you have any questions or need assistance, our support team is here to help!</p>

        <div style="text-align: center;">
            <a href="{{ route('dashboard') }}" class="cta-button">Access Your Dashboard</a>
        </div>

        <div class="footer">
            <p>Thank you for your business! We're excited to have you as a customer.</p>
            
            <div class="contact-info">
                <p>Support Email: support@{{ config('app.domain', 'qricket.com') }}</p>
                <p>Phone: +63 XXX XXX XXXX</p>
                <p>Business Hours: Monday - Friday, 9:00 AM - 6:00 PM (PHT)</p>
            </div>
        </div>
    </div>
</body>
</html> 