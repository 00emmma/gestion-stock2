<?php
// diagnostic_users.php - VERSION CORRIGÉE
echo "<h1>🔍 DIAGNOSTIC COMPLET - PROBLEME CONNEXION</h1>";

$conn = new mysqli("localhost", "root", "", "stoch_db");

if ($conn->connect_error) {
    die("❌ ERREUR CONNEXION BD: " . $conn->connect_error);
}

echo "✅ Connexion BD réussie<br>";

// 1. Vérifier si la table existe
echo "<h2>1. Vérification table 'utilisateurs'</h2>";
$result = $conn->query("SHOW TABLES LIKE 'utilisateurs'");
if ($result->num_rows === 0) {
    echo "❌ TABLE 'UTILISATEURS' N'EXISTE PAS!<br>";
} else {
    echo "✅ Table 'utilisateurs' existe<br>";
}

// 2. Vérifier la structure
echo "<h2>2. Structure de la table</h2>";
$result = $conn->query("DESCRIBE utilisateurs");
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'><th>Champ</th><th>Type</th><th>Null</th><th>Clé</th><th>Défaut</th><th>Extra</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td><strong>" . $row['Field'] . "</strong></td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "<td>" . $row['Default'] . "</td>";
    echo "<td>" . $row['Extra'] . "</td>";
    echo "</tr>";
}
echo "</table>";

// 3. Vérifier les utilisateurs existants (avec les bons noms de colonnes)
echo "<h2>3. Utilisateurs dans la base</h2>";
$result = $conn->query("SELECT id_utilisateur, nom, email, role, statut, date_creation FROM utilisateurs");
if ($result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr style='background: #e0e0e0;'><th>ID</th><th>Nom</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Créé le</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $status_color = $row['statut'] == 'actif' ? 'green' : 'red';
        $role_color = $row['role'] == 'admin' ? 'blue' : 'green';
        echo "<tr>";
        echo "<td>" . $row['id_utilisateur'] . "</td>";
        echo "<td><strong>" . htmlspecialchars($row['nom']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td style='color: $role_color;'><strong>" . $row['role'] . "</strong></td>";
        echo "<td style='color: $status_color;'><strong>" . $row['statut'] . "</strong></td>";
        echo "<td>" . $row['date_creation'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "❌ AUCUN UTILISATEUR TROUVÉ!<br>";
}

// 4. Créer un utilisateur de test si nécessaire
echo "<h2>4. Création utilisateur de test</h2>";
$test_email = "user@test.com";
$check_user = $conn->query("SELECT id_utilisateur FROM utilisateurs WHERE email = '$test_email'");

if ($check_user->num_rows === 0) {
    $password_hash = password_hash("user123", PASSWORD_DEFAULT);
    $insert_sql = "INSERT INTO utilisateurs (nom, email, mot_de_passe, role, statut) 
                   VALUES ('Utilisateur Test', '$test_email', '$password_hash', 'user', 'actif')";
    
    if ($conn->query($insert_sql)) {
        echo "✅ Utilisateur test créé: <strong>user@test.com</strong> / <strong>user123</strong><br>";
    } else {
        echo "❌ Erreur création utilisateur: " . $conn->error . "<br>";
    }
} else {
    echo "✅ Utilisateur test existe déjà<br>";
}

// 5. Tester la requête de connexion
echo "<h2>5. Test requête de connexion</h2>";
$test_email = "user@test.com";
$sql = "SELECT * FROM utilisateurs WHERE email = ? AND role = 'user' AND statut = 'actif'";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("s", $test_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "Requête testée: <code>$sql</code><br>";
    echo "Email testé: <strong>$test_email</strong><br>";
    echo "Résultats trouvés: <strong>" . $result->num_rows . "</strong><br>";
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo "✅ Utilisateur trouvé:<br>";
        echo "<pre>" . print_r($user, true) . "</pre>";
        
        // Tester le mot de passe
        $test_password = "user123";
        if (password_verify($test_password, $user['mot_de_passe'])) {
            echo "✅ Mot de passe VALIDE pour 'user123'<br>";
        } else {
            echo "❌ Mot de passe INVALIDE pour 'user123'<br>";
            echo "Hash stocké: " . $user['mot_de_passe'] . "<br>";
        }
    } else {
        echo "❌ Aucun utilisateur trouvé avec cette requête<br>";
        
        // Tester sans les conditions de rôle et statut
        echo "<h3>Test sans conditions de rôle/statut:</h3>";
        $sql2 = "SELECT * FROM utilisateurs WHERE email = ?";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("s", $test_email);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        
        echo "Requête: <code>$sql2</code><br>";
        echo "Résultats: <strong>" . $result2->num_rows . "</strong><br>";
        
        if ($result2->num_rows > 0) {
            $user2 = $result2->fetch_assoc();
            echo "Utilisateur trouvé (sans filtre):<br>";
            echo "<pre>" . print_r($user2, true) . "</pre>";
            echo "Rôle: " . $user2['role'] . "<br>";
            echo "Statut: " . $user2['statut'] . "<br>";
        }
        $stmt2->close();
    }
    $stmt->close();
} else {
    echo "❌ Erreur préparation requête: " . $conn->error . "<br>";
}

$conn->close();

echo "<hr>";
echo "<h2>🎯 SOLUTION RAPIDE</h2>";
echo "<p>Utilisez ces identifiants pour tester:</p>";
echo "<ul>";
echo "<li><strong>Email:</strong> user@test.com</li>";
echo "<li><strong>Mot de passe:</strong> user123</li>";
echo "</ul>";
echo "<p><a href='login_user.php' style='background: #4361ee; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔗 Tester la connexion</a></p>";
?>