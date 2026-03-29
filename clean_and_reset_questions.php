
// clean_and_reset_questions.php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "stoch_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Erreur de connexion : " . $conn->connect_error);
}

$email = 'user@test.com';

echo "<h3>Nettoyage et réinitialisation des questions</h3>";

// Trouver l'utilisateur
$stmt = $conn->prepare("SELECT id_utilisateur FROM utilisateurs WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    $user_id = $user['id_utilisateur'];
    
    // 1. Supprimer toutes les questions existantes
    echo "1. Suppression des anciennes questions...<br>";
    $stmt = $conn->prepare("DELETE FROM questions_securite WHERE id_utilisateur = ?");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        echo "✓ Questions supprimées<br>";
    }
    
    // 2. Insérer les nouvelles questions avec les bonnes réponses
    echo "2. Insertion des nouvelles questions...<br>";
    $questions = [
        ['question' => 'Quel est le nom de votre ville de naissance ?', 'reponse' => 'paris'],
        ['question' => 'Quel est le nom de votre animal de compagnie ?', 'reponse' => 'medor'],
        ['question' => 'Quel est le nom de jeune fille de votre mère ?', 'reponse' => 'dubois']
    ];
    
    foreach ($questions as $q) {
        $stmt = $conn->prepare("INSERT INTO questions_securite (id_utilisateur, question, reponse_hash) VALUES (?, ?, SHA2(?, 256))");
        $stmt->bind_param("iss", $user_id, $q['question'], $q['reponse']);
        
        if ($stmt->execute()) {
            echo "✓ Question ajoutée: {$q['question']} (réponse: {$q['reponse']})<br>";
        }
    }
    
    echo "<br><strong>✅ Réinitialisation terminée avec succès !</strong><br>";
    echo "Réponses à utiliser :<br>";
    echo "- Ville de naissance: <strong>paris</strong><br>";
    echo "- Animal de compagnie: <strong>medor</strong><br>";
    echo "- Nom de jeune fille de la mère: <strong>dubois</strong><br>";
    
} else {
    echo "Utilisateur non trouvé: $email";
}

$conn->close();
?>