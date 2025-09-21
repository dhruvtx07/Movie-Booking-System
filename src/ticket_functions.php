<?php
require_once 'phpqrcode/qrlib.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

class TicketFunctions {
    /**
     * Generate QR code for a booking
     */
    public static function generateQRCode($bookingData) {
        $qrData = http_build_query([
            'booking_ref' => $bookingData['booking_ref'],
            'event_id' => $bookingData['event_id'],
            'venue_id' => $bookingData['venue_id'],
            'schedule_id' => $bookingData['schedule_id']
        ]);

        $qrCodeDir = 'temp/qrcodes/';
        
        try {
            // Create directory if it doesn't exist
            if (!file_exists($qrCodeDir)) {
                if (!mkdir($qrCodeDir, 0755, true)) {
                    throw new Exception('Failed to create QR code directory');
                }
            }
            
            // Sanitize booking ref for filename
            $sanitizedRef = preg_replace('/[^a-zA-Z0-9_-]/', '', $bookingData['booking_ref']);
            $qrCodeFilename = 'qr_' . $sanitizedRef . '.png';
            $qrCodePath = $qrCodeDir . $qrCodeFilename;
            
            // Generate QR code and save to file
            QRcode::png($qrData, $qrCodePath, QR_ECLEVEL_L, 10, 2);
            
            // Verify QR code was created
            if (!file_exists($qrCodePath)) {
                throw new Exception('QR code generation failed');
            }
            
            return $qrCodePath;
            
        } catch (Exception $e) {
            error_log('QR code generation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send booking confirmation email with QR code
     */
    public static function sendBookingEmail($customerEmail, $bookingData, $qrCodePath) {
        try {
            // Format date and time
            $eventDate = date('l, F j, Y', strtotime($bookingData['slot_time']));
            $eventTime = date('h:i A', strtotime($bookingData['slot_time']));

            // Group tickets by type and collect locations
            $groupedTickets = [];
            foreach ($bookingData['tickets'] as $ticket) {
                $type = $ticket['type'];
                if (!isset($groupedTickets[$type])) {
                    $groupedTickets[$type] = [
                        'tickets' => [],
                        'locations' => []
                    ];
                }
                $groupedTickets[$type]['tickets'][] = $ticket;
                if (!empty($ticket['location'])) {
                    $groupedTickets[$type]['locations'][] = $ticket['location'];
                }
            }

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'tdhruv425@gmail.com';
            $mail->Password = 'sdfd ymcz wpoh vslw';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            
            // Sender and recipient
            $mail->setFrom('tdhruv425@gmail.com', 'Your Booking System');
            $mail->addAddress($customerEmail);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Booking Confirmation: ' . htmlspecialchars($bookingData['event_name']);
            
            // Add the QR code as an embedded image
            $qrCID = 'qr-code-' . $bookingData['booking_ref'];
            $mail->addEmbeddedImage($qrCodePath, $qrCID, 'ticket_qr_code.png');
            
            // Build email body
            $emailBody = self::generateEmailTemplate($bookingData, $qrCID, $eventDate, $eventTime, $groupedTickets);
            
            $mail->Body = $emailBody;
            $mail->AltBody = self::generatePlainTextEmail($bookingData, $eventDate, $eventTime, $groupedTickets);
            
            // Also attach the QR code as a separate file
            $mail->addAttachment($qrCodePath, 'ticket_qr_code.png');
            
            // Send email
            if ($mail->send()) {
                return ['status' => 'success', 'message' => 'Email sent successfully'];
            } else {
                return ['status' => 'error', 'message' => 'Mailer Error: ' . $mail->ErrorInfo];
            }
            
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Exception Error: ' . $e->getMessage()];
        }
    }

    /**
     * Generate HTML email template
     */
    private static function generateEmailTemplate($bookingData, $qrCID, $eventDate, $eventTime, $groupedTickets) {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; }
                .email-container { border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                .email-header { background: linear-gradient(135deg, #e63946 0%, #ff7e33 100%); color: white; padding: 30px; text-align: center; }
                .email-header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                .email-body { padding: 30px; background: #ffffff; }
                .event-title { color: #e63946; font-size: 24px; font-weight: 700; margin-bottom: 20px; }
                .booking-id { font-size: 18px; font-weight: bold; color: #e63946; margin-bottom: 20px; background: rgba(230, 57, 70, 0.1); padding: 10px 15px; border-radius: 6px; display: inline-block; }
                .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; }
                .info-item { margin-bottom: 8px; }
                .ticket-card { border-left: 4px solid #e63946; margin-bottom: 15px; background: #f9f9f9; border-radius: 6px; padding: 15px; }
                .ticket-type { background: linear-gradient(135deg, #e63946 0%, #ff7e33 100%); color: white; padding: 4px 10px; border-radius: 4px; font-size: 14px; display: inline-block; margin-bottom: 10px; }
                .qr-card { background: white; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-top: 4px solid #e63946; padding: 20px; max-width: 300px; margin: 20px auto; text-align: center; }
                .qr-card-header h4 { color: #e63946; margin: 0 0 5px 0; font-size: 18px; }
                .qr-card-header p { color: #666; margin: 0; font-size: 14px; }
                .qr-code-wrapper { background: white; padding: 10px; border-radius: 8px; display: inline-block; border: 1px solid #eee; }
                .qr-card-footer { margin-top: 15px; font-size: 13px; color: #666; }
                .qr-ref { font-weight: bold; color: #e63946; word-break: break-all; }
                .payment-summary { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-top: 20px; }
                .divider { height: 1px; background: #eee; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #777; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <h1><i class="fas fa-check-circle"></i> Booking Confirmed!</h1>
                    <p>Your tickets for ' . htmlspecialchars($bookingData['event_name']) . '</p>
                </div>
                
                <div class="email-body">
                    <h2 class="event-title">' . htmlspecialchars($bookingData['event_name']) . '</h2>
                    
                    <div class="booking-id">Booking Reference: ' . $bookingData['booking_ref'] . '</div>
                    
                    <div class="info-grid">
                        <div>
                            <div class="info-item"><i class="fas fa-calendar-day"></i> <strong>Date:</strong> ' . $eventDate . '</div>
                            <div class="info-item"><i class="fas fa-clock"></i> <strong>Time:</strong> ' . $eventTime . '</div>
                        </div>
                        <div>
                            <div class="info-item"><i class="fas fa-map-marker-alt"></i> <strong>Venue:</strong> ' . htmlspecialchars($bookingData['venue_name']) . '</div>
                            <div class="info-item"><i class="fas fa-map-marker-alt"></i> <strong>Sub Venue:</strong> ' . htmlspecialchars($bookingData['sub_venue_name']) . '</div>
                        </div>
                    </div>
                    
                    <h3><i class="fas fa-ticket-alt"></i> Your Tickets</h3>';
        
        // Add tickets section
        foreach ($groupedTickets as $type => $data) {
            $html .= '
            <div class="ticket-card">
                <span class="ticket-type">' . htmlspecialchars($type) . ' (' . count($data['tickets']) . ')</span>
                <div><i class="fas fa-chair"></i> Seats: ' . htmlspecialchars(implode(', ', array_unique($data['locations']))) . '</div>
            </div>';
        }
        
        // Add QR code section
        $html .= '      
                <div class="qr-card">
                    <div class="qr-card-header">
                        <h4><i class="fas fa-qrcode"></i> ENTRY PASS</h4>
                        <p>Present this at the venue</p>
                    </div>
                    <div class="qr-code-wrapper">
                        <img src="cid:' . $qrCID . '" alt="QR Code" style="width: 180px; height: 180px;">
                    </div>
                    <div class="qr-card-footer">
                        <div><strong>Event:</strong> ' . htmlspecialchars(substr($bookingData['event_name'], 0, 30)) . '</div>
                        <div><strong>Date:</strong> ' . date('M j, Y', strtotime($bookingData['slot_time'])) . '</div>
                        <div><strong>Time:</strong> ' . date('g:i A', strtotime($bookingData['slot_time'])) . '</div>
                        <div style="margin-top: 8px;"><span class="qr-ref">Ref: ' . $bookingData['booking_ref'] . '</span></div>
                    </div>
                </div>

                <div class="payment-summary">
                    <h3><i class="fas fa-receipt"></i> Payment Summary</h3>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>Tickets:</span>
                        <span>₹' . number_format($bookingData['total_amount'], 2) . '</span>
                    </div>';

        if (isset($bookingData['promo_discount']) && $bookingData['promo_discount'] > 0) {
            $html .= '
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: #28a745;">
                        <span>Discount:</span>
                        <span>-₹' . number_format($bookingData['promo_discount'], 2) . '</span>
                    </div>';
        }
        
        $html .= '
                    <div class="divider"></div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold;">
                        <span>Total Paid:</span>
                        <span>₹' . number_format($bookingData['total_amount'], 2) . '</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold;">
                        <span>Paid Via</span>
                        <span>: ' . htmlspecialchars(substr($bookingData['payment_method'], 0, 30)) . '</span>
                    </div>
                </div>
                
                <div class="divider"></div>
                
                <p>Thank you for your booking! Present this email or the QR code at the venue for entry.</p>
                <p>If you have any questions, please contact our support team.</p>
                
                <div class="footer">
                    <p>© ' . date('Y') . ' Your Booking System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }

    /**
     * Generate plain text email fallback
     */
    private static function generatePlainTextEmail($bookingData, $eventDate, $eventTime, $groupedTickets) {
        $text = "Booking Confirmation\n\n" .
            "Event: " . $bookingData['event_name'] . "\n" .
            "Date: " . $eventDate . "\n" .
            "Time: " . $eventTime . "\n" .
            "Venue: " . $bookingData['venue_name'] . "\n" .
            "Booking Reference: " . $bookingData['booking_ref'] . "\n\n" .
            "Tickets:\n";
        
        foreach ($groupedTickets as $type => $data) {
            $text .= $type . " (" . count($data['tickets']) . "): " . 
                     implode(', ', array_unique($data['locations'])) . "\n";
        }
        
        $text .= "\nTotal Paid: ₹" . number_format($bookingData['total_amount'], 2) . "\n\n" .
                "Thank you for your booking! Please find the QR code attached to this email.";
        
        return $text;
    }

    /**
     * Generate QR card HTML for download
     */
    public static function generateQrCardHtml($qrImageSrc, $bookingRef, $eventName, $eventDate, $eventTime, $venueName) {
        return '
        <div id="qr-card-container" style="
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-top: 4px solid #e63946;
            padding: 20px;
            width: 300px;
            text-align: center;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        ">
            <div style="margin-bottom: 15px;">
                <h4 style="color: #e63946; margin: 0 0 5px 0; font-size: 18px;">
                    <i class="fas fa-qrcode"></i> ENTRY PASS
                </h4>
                <p style="color: #666; margin: 0; font-size: 14px;">Present this at the venue</p>
            </div>
            <div style="background: white; padding: 10px; border-radius: 8px; display: inline-block; border: 1px solid #eee;">
                <img src="'.$qrImageSrc.'" alt="QR Code" style="width: 180px; height: 180px;">
            </div>
            <div style="margin-top: 15px; font-size: 13px; color: #666;">
                <div><strong>Event:</strong> '.substr(htmlspecialchars($eventName), 0, 30).'</div>
                <div><strong>Date:</strong> '.$eventDate.'</div>
                <div><strong>Venue:</strong> '.substr(htmlspecialchars($venueName), 0, 30).'</div>
                <div><strong>Time:</strong> '.$eventTime.'</div>
                <div style="margin-top: 8px;">
                    <span style="font-weight: bold; color: #e63946; word-break: break-all;">
                        Ref: '.$bookingRef.'
                    </span>
                </div>
            </div>
        </div>';
    }
}