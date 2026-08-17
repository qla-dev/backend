<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Freightbook.ai account is ready</title>
</head>
<body style="margin:0;background:#f8fafc;color:#0f172a;font-family:Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:40px 20px;">
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;padding:32px;">
            <h1 style="margin:0 0 16px;font-size:24px;">Welcome to Freightbook.ai</h1>
            <p>Hello {{ $customerName }},</p>
            <p>Your Freightbook.ai customer account has been authorized. Use these credentials for your first sign-in:</p>
            <div style="margin:24px 0;padding:20px;border-radius:12px;background:#f0f9ff;border:1px solid #bae6fd;">
                <p style="margin:0 0 10px;"><strong>Username:</strong> {{ $username }}</p>
                <p style="margin:0;"><strong>Temporary password:</strong> {{ $temporaryPassword }}</p>
            </div>
            <p>Please change your temporary password after signing in.</p>
            <p style="margin-bottom:0;color:#64748b;">Freightbook.ai</p>
        </div>
    </div>
</body>
</html>
