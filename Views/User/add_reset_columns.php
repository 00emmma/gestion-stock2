<?php
// add_reset_columns.php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "stoch_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Erreur de connexion : " . $conn->connect_error);
}

echo "<h3>Ajout des colonnes de réinitialisation</h3>";

// Vérifier si les colonnes existent déjà
$check_sql = "SHOW COLUMNS FROM utilisateurs LIKE 'reset_token'";
$result = $conn->query($check_sql);

if ($result->num_rows > 0) {
    echo "✓ Les colonnes existent déjà.<br>";
} else {
    // Ajouter les colonnes
    $alter_sql = "ALTER TABLE utilisateurs 
                  ADD COLUMN reset_token VARCHAR(64) NULL,
                  ADD COLUMN token_expiration DATETIME NULL,
                  ADD INDEX idx_reset_token (reset_token)";
    
    if ($conn->query($alter_sql) === TRUE) {
        echo "✓ Colonnes ajoutées avec succès !<br>";
        echo "- reset_token (VARCHAR 64)<br>";
        echo "- token_expiration (DATETIME)<br>";
    } else {
        echo "✗ Erreur lors de l'ajout des colonnes : " . $conn->error . "<br>";
    }
}

// Vérifier la structure de la table
echo "<h4>Structure de la table utilisateurs :</h4>";
$structure_sql = "SHOW COLUMNS FROM utilisateurs";
$result = $conn->query($structure_sql);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['Field']}</td>";
    echo "<td>{$row['Type']}</td>";
    echo "<td>{$row['Null']}</td>";
    echo "<td>{$row['Key']}</td>";
    echo "</tr>";
}
echo "</table>";

$conn->close();
?>