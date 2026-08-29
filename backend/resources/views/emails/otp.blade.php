<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>Your verification code</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5; font-family:'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5; padding:24px 16px;">
        <tr>
            <td align="center">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08);">

                    <!-- Header -->
                    <tr>
                        <td align="center" style="background-color:#c21e40; padding:28px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="background-color:#ffffff; border-radius:50%; padding:10px; width:84px; height:84px;">
                                        <img src="{{ $logoCid }}" alt="BTS Bank" width="64" height="64" style="display:block; border:0;">
                                    </td>
                                </tr>
                            </table>
                            <div style="color:#ffffff; font-size:20px; font-weight:700; letter-spacing:2px; margin-top:14px;">
                                BTS BANK
                            </div>
                            <div style="height:3px; width:56px; background-color:#ffffff; margin:10px auto 0; border-radius:2px; opacity:0.85;"></div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:36px 40px 8px 40px;">
                            <h1 style="margin:0 0 8px 0; font-size:20px; font-weight:600; color:#8f1730;">
                                Verify it's you
                            </h1>
                            <p style="margin:0; font-size:14px; line-height:1.6; color:#5a6478;">
                                Enter the code below in the app to confirm your identity. This code is valid for
                                <strong style="color:#8f1730;">{{ $ttlMinutes }} minutes</strong> and can only be used once.
                            </p>
                        </td>
                    </tr>

                    <!-- Code box -->
                    <tr>
                        <td align="center" style="padding:28px 40px 8px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#fdeef1; border:1px dashed #d9879a; border-radius:10px;">
                                <tr>
                                    <td align="center" style="padding:24px 16px;">
                                        <div style="font-size:34px; font-weight:700; letter-spacing:14px; color:#c21e40; font-family:Consolas, Menlo, monospace; padding-left:14px;">
                                            {{ $code }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Security note -->
                    <tr>
                        <td style="padding:24px 40px 4px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td width="34" valign="top" style="padding-top:2px;">
                                        <span style="display:inline-block; font-size:16px;">&#128274;</span>
                                    </td>
                                    <td valign="top">
                                        <p style="margin:0; font-size:13px; line-height:1.55; color:#7a849b;">
                                            BTS Bank will <strong>never</strong> call, email, or message you asking for this code.
                                            If you didn't request it, you can safely ignore this email.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Divider -->
                    <tr>
                        <td style="padding:24px 40px 8px 40px;">
                            <div style="height:1px; background-color:#e6e6e6;"></div>
                        </td>
                    </tr>

                    <!-- Support -->
                    <tr>
                        <td style="padding:8px 40px 0 40px;">
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#7a849b;">
                                Need help? Contact our support team at
                                <a href="mailto:support@btsbank.tn" style="color:#c21e40; font-weight:600; text-decoration:none;">support@btsbank.tn</a>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#fafafa; padding:20px 40px; border-top:1px solid #e6e6e6;">
                            <p style="margin:0 0 4px 0; font-size:11px; line-height:1.5; color:#a2a2a2; text-align:center;">
                                &copy; {{ date('Y') }} BTS Bank. All rights reserved.
                            </p>
                            <p style="margin:0; font-size:11px; line-height:1.5; color:#a2a2a2; text-align:center;">
                                This is an automated message — please do not reply.
                            </p>
                        </td>
                    </tr>

                </table>

                <p style="margin:16px 0 0 0; font-size:11px; color:#a2a2a2; text-align:center;">
                    Sent from our secure verification service.
                </p>

            </td>
        </tr>
    </table>

</body>
</html>