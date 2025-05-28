<?php

require_once __DIR__ . '/vendor/autoload.php';

use Postmark\PostmarkClient;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Set default values for environment variables
$_ENV['DB_HOST'] = $_ENV['DB_HOST'] ?? 'localhost';

$_ENV['LENGTH_OF_SLOT'] = $_ENV['LENGTH_OF_SLOT'] ?? 20;

try {
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
} 

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $time_id = filter_input(INPUT_POST, 'time_id', FILTER_SANITIZE_NUMBER_INT);

    if ($name && $email && $time_id) {
        try {
            $pdo->beginTransaction();

            // Check if the time slot is still available
            $stmt = $pdo->prepare("SELECT available FROM times WHERE id = ?");
            $stmt->execute([$time_id]);
            $timeSlot = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$timeSlot || $timeSlot['available'] != 1) {
                throw new Exception("This time slot is no longer available. Please select another time.");
            }

            // Get the appointment time for the email
            $stmt = $pdo->prepare("SELECT time FROM times WHERE id = ?");
            $stmt->execute([$time_id]);
            $appointmentTime = $stmt->fetch(PDO::FETCH_ASSOC)['time'];
            $datetimePacific = new DateTime($appointmentTime, new DateTimeZone('America/New_York'));
            $datetimePacific->setTimezone(new DateTimeZone('America/Los_Angeles'));
            $formattedTimePacific = $datetimePacific->format('F j, Y g:i A');

            $datetimeEastern = new DateTime($appointmentTime, new DateTimeZone('America/New_York'));
            $formattedTimeEastern = $datetimeEastern->format('F j, Y g:i A');

            // Insert into appointments table
            $stmt = $pdo->prepare("INSERT INTO appointments (name, email, time_id) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $time_id]);

            // Update times table
            $stmt = $pdo->prepare("UPDATE times SET available = 0 WHERE id = ?");
            $stmt->execute([$time_id]);

            // Send confirmation emails
            $client = new PostmarkClient($_ENV['POSTMARK_API_KEY']);

            $message1 = array(
                'To' => $email,
                'From' => $_ENV['FROM_NAME'] . " <" . $_ENV['FROM_EMAIL'] . ">",
                'Subject' => "Website Hacking Lab Time Confirmation",
                'TextBody' => "Hello {$name},\n\n" .
                "Your meeting time has been confirmed for {$formattedTimePacific} Pacific Time.\n\n" .
                "Use the following link to join the meeting:\n" . $_ENV['MEETING_LINK'] . "\n\n" .
                "If you are meeting with me in hopes I will help you solve a problem with the site you are building, emailing me a publicly viewable link and a short description of your problem ahead of time may help our session be more productive." . "\n\n" .
                "See you then!\n\n"
            );

            $message2 = array(
                'To' => $_ENV['FROM_EMAIL'],
                'From' => "Website Hacking Lab <" . $_ENV['FROM_EMAIL'] . ">",
                'Subject' => "New Appointment Booking",
                'ReplyTo' => $name . " <" . $email . ">",
                'TextBody' => "A new Website Hacking Lab appointment has been booked:\n\n" .
                "Student: {$name}\n" .
                "Email: {$email}\n" .
                "Time: {$formattedTimeEastern} Eastern Time\n\n"
            );  

            $client->sendEmailBatch([$message1, $message2]);

            $pdo->commit();
            $success = "Appointment booked successfully! A confirmation email has been sent.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error booking appointment: " . $e->getMessage();
        }
    }
}

// Get available time slots that haven't exceeded their term limits
$stmt = $pdo->query("
    SELECT t.id, t.time 
    FROM times t
    JOIN terms tr ON t.time BETWEEN tr.start AND tr.end
    LEFT JOIN (
        SELECT time_id, COUNT(*) as appointment_count
        FROM appointments
        GROUP BY time_id
    ) a ON t.id = a.time_id
    WHERE t.available = 1
    AND (
        SELECT COUNT(*)
        FROM appointments ap
        JOIN times ts ON ap.time_id = ts.id
        WHERE ts.time BETWEEN tr.start AND tr.end
    ) < tr.slots
    ORDER BY t.time
");
$timeSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current term's remaining slots
$stmt = $pdo->query("
    SELECT tr.slots - (
        SELECT COUNT(*)
        FROM appointments ap
        JOIN times ts ON ap.time_id = ts.id
        WHERE ts.time BETWEEN tr.start AND tr.end
    ) as remaining_slots,
    tr.start,
    tr.end,
    tr.name
    FROM terms tr
    WHERE NOW() BETWEEN tr.start AND tr.end
    LIMIT 1
");
$termInfo = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Website Hacking Lab Time Slots</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-gray-900 text-3xl font-bold text-center mb-2">Website Hacking Lab</h1>
        <h2 class="text-gray-800 text-xl font-bold text-center mb-8">Book a <?php echo $_ENV['LENGTH_OF_SLOT']; ?>-minute Appointment</h2>
        
        <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="max-w-md mx-auto bg-white rounded-lg shadow-md p-6">
            <div class="mb-4">
                <label for="name" class="block text-gray-700 font-bold mb-2">Name</label>
                <input type="text" id="name" name="name" required
                    class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="mb-4">
                <label for="email" class="block text-gray-700 font-bold mb-2">Email</label>
                <input type="email" id="email" name="email" required
                    class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="mb-6">
                <?php if ($termInfo): ?>
                    <div class="mb-4">
                        <p class="text-gray-700">
                            <?php 
                            echo '<label class="block text-gray-700 font-bold mb-2">'.$termInfo['name'].'</label>';
                            ?>
                        </p>
                        <p class="text-center font-semibold text-blue-600">
                            <?php echo $termInfo['remaining_slots']; ?> appointments remaining
                        </p>
                    </div>
                <?php endif; ?>
                <label class="block text-gray-700 font-bold mb-2">Available Time Slots</label>
                <div class="grid grid-cols-2 gap-4">
                    <?php if (empty($timeSlots)): ?>
                        <div class="col-span-2 text-center text-red-500 font-semibold">No available times</div>
                    <?php else: ?>
                        <?php foreach ($timeSlots as $slot): ?>
                            <?php
                            $datetime = new DateTime($slot['time'], new DateTimeZone('America/New_York'));
                            $datetime->setTimezone(new DateTimeZone('America/Los_Angeles'));
                            $formattedTime = htmlspecialchars($datetime->format('M j, Y')) . '<br>' . htmlspecialchars($datetime->format('g:i A'));
                            ?>
                            <label class="relative">
                                <input type="radio" name="time_id" value="<?php echo $slot['id']; ?>" required
                                    class="peer sr-only">
                                <div class="p-4 border rounded-lg cursor-pointer hover:bg-blue-50 peer-checked:bg-blue-100 peer-checked:border-blue-500">
                                    <?php echo $formattedTime; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit"
                class="w-full bg-blue-500 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 <?php echo empty($timeSlots) ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                <?php echo empty($timeSlots) ? 'disabled' : ''; ?>>
                Book Appointment
            </button>
        </form>
    </div>
</body>
</html>