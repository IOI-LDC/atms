<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $heading }}</title>

  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:AllowPNG/>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->

  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-lspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
    body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; background-color: #f1f5f9 !important; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
    .column { width: 100%; max-width: 260px; display: inline-block; vertical-align: top; }
    @media screen and (max-width: 600px) {
      .responsive-table { width: 100% !important; }
      .column { max-width: 100% !important; display: block !important; width: 100% !important; }
      .mobile-pad { padding-left: 20px !important; padding-right: 20px !important; }
      .mobile-header { font-size: 22px !important; }
      .mobile-spacer { height: 16px !important; }
    }
    [data-ogsc] .dark-text,
    @media (prefers-color-scheme: dark) {
      .dark-text { color: #0f172a !important; }
      .gray-text { color: #64748b !important; }
      .bg-white { background-color: #ffffff !important; }
    }
  </style>
</head>
<body class="bg-white" style="margin: 0; padding: 0; background-color: #f1f5f9;">

  <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f1f5f9;">
    <tr>
      <td align="center" style="padding: 40px 10px;">

        <!--[if (gte mso 9)|(IE)]>
        <table align="center" border="0" cellspacing="0" cellpadding="0" width="600">
        <tr>
        <td align="center" valign="top" width="600">
        <![endif]-->

        <table class="responsive-table bg-white" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden;">

          <tr>
            <td style="background-color: #d97706; height: 4px; font-size: 0px; line-height: 0px;">&nbsp;</td>
          </tr>

          <tr>
            <td class="mobile-pad" style="padding: 32px 40px; background-color: #21274b;">
              <p style="margin: 0 0 8px; font-size: 12px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: #94a3b8;">ATMS &middot; {{ $notificationType }}</p>
              <h1 class="mobile-header" style="margin: 0; font-size: 26px; font-weight: 700; color: #ffffff; line-height: 1.3;">{{ $heading }}</h1>
            </td>
          </tr>

          <tr>
            <td class="mobile-pad bg-white" style="padding: 32px 40px 10px; background-color: #ffffff;">
              <p class="dark-text" style="margin: 0 0 24px; font-size: 16px; color: #0f172a; line-height: 1.6;">
                Dear {{ $recipientName }},<br><br>
                {{ $bodyMessage }}
              </p>
            </td>
          </tr>

          <tr>
            <td class="mobile-pad bg-white" style="padding: 0 40px; background-color: #ffffff;">
              <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 16px 0;">
                <tr>
                  <td style="padding: 16px 0; font-size: 0;">

                    <!--[if (gte mso 9)|(IE)]>
                    <table border="0" cellspacing="0" cellpadding="0" width="100%">
                    <tr>
                    <td align="left" valign="top" width="260">
                    <![endif]-->
                    <div class="column">
                      <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td style="padding: 14px 0 22px;">
                            <p class="gray-text" style="margin: 0 0 4px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b;">{{ $grid[0]['label'] }}</p>
                            <p class="dark-text" style="margin: 0; font-size: 15px; font-weight: 600; color: #0f172a;">{{ $grid[0]['value'] }}</p>
                          </td>
                        </tr>
                        <tr>
                          <td>
                            <p class="gray-text" style="margin: 0 0 4px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b;">{{ $grid[2]['label'] }}</p>
                            <p class="dark-text" style="margin: 0; font-size: 15px; font-weight: 500; color: #0f172a;">{{ $grid[2]['value'] }}</p>
                          </td>
                        </tr>
                      </table>
                    </div>
                    <!--[if (gte mso 9)|(IE)]>
                    </td>
                    <td align="left" valign="top" width="260">
                    <![endif]-->

                    <div class="mobile-spacer" style="display: none; height: 0px;"></div>

                    <div class="column">
                      <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td style="padding: 14px 0 22px;">
                            <p class="gray-text" style="margin: 0 0 4px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b;">{{ $grid[1]['label'] }}</p>
                            <p class="dark-text" style="margin: 0; font-size: 15px; font-weight: 500; color: #0f172a;">{{ $grid[1]['value'] }}</p>
                          </td>
                        </tr>
                        <tr>
                          <td>
                            <p class="gray-text" style="margin: 0 0 4px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b;">{{ $grid[3]['label'] }}</p>
                            <p class="dark-text" style="margin: 0; font-size: 15px; font-weight: 500; color: #0f172a;">{{ $grid[3]['value'] }}</p>
                          </td>
                        </tr>
                      </table>
                    </div>
                    <!--[if (gte mso 9)|(IE)]>
                    </td>
                    </tr>
                    </table>
                    <![endif]-->

                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td class="mobile-pad bg-white" style="padding: 16px 40px 32px; background-color: #ffffff;">
              <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td>
                    <p class="gray-text" style="margin: 0 0 4px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b;">{{ $descriptionLabel }}</p>
                    <p class="dark-text" style="margin: 0; font-size: 15px; line-height: 1.5; color: #0f172a;">{{ $descriptionValue }}</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td class="mobile-pad bg-white" align="center" style="padding: 10px 40px 40px; background-color: #ffffff;">
              <table border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="background-color: #21274b; border-radius: 4px;">
                    <a href="{{ $actionUrl }}" target="_blank" style="display: inline-block; padding: 14px 36px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 4px;">{{ $actionLabel ?? 'Open in ATMS' }}</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td class="mobile-pad" align="center" style="padding: 24px 40px; background-color: #f8fafc; border-top: 1px solid #e2e8f0;">
              <p class="gray-text" style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.6;">
                This is an automated notification from the <strong>Asset Tracking and Maintenance System</strong>.<br>
                Please do not reply to this email.
              </p>
            </td>
          </tr>

        </table>

        <!--[if (gte mso 9)|(IE)]>
        </td>
        </tr>
        </table>
        <![endif]-->

      </td>
    </tr>
  </table>

</body>
</html>
