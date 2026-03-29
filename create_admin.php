<?php
// Définir l'encodage
header('Content-Type: text/html; charset=utf-8');

// Connexion à la base de données - CORRIGÉ
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "stoch_db";  // Nom de votre base de données

try {
    // Connexion PDO
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Ajout d'un nouvel administrateur</h2>";
    
    // Vérifier si le formulaire a été soumis
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Récupérer et sécuriser les données
        $nom = trim($_POST['nom']);
        $email = trim($_POST['email']);
        $telephone = trim($_POST['telephone']);
        $mot_de_passe = $_POST['mot_de_passe'];
        $confirmation = $_POST['confirmation'];
        $permissions = trim($_POST['permissions'] ?? NULL);
        
        // Validation des données
        $erreurs = [];
        
        // Vérification des champs obligatoires
        if (empty($nom)) {
            $erreurs[] = "Le nom est obligatoire.";
        }
        
        if (empty($email)) {
            $erreurs[] = "L'email est obligatoire.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "Format d'email invalide.";
        }
        
        if (empty($mot_de_passe)) {
            $erreurs[] = "Le mot de passe est obligatoire.";
        } elseif (strlen($mot_de_passe) < 8) {
            $erreurs[] = "Le mot de passe doit contenir au moins 8 caractères.";
        }
        
        if ($mot_de_passe !== $confirmation) {
            $erreurs[] = "Les mots de passe ne correspondent pas.";
        }
        
        // Vérifier si l'email existe déjà
        $stmt = $conn->prepare("SELECT id_utilisateur FROM utilisateurs WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $erreurs[] = "Cet email est déjà utilisé.";
        }
        
        // Si aucune erreur, procéder à l'insertion
        if (empty($erreurs)) {
            // Hacher le mot de passe
            $hash_mot_de_passe = password_hash($mot_de_passe, PASSWORD_DEFAULT);
            
            // Date actuelle
            $date_creation = date('Y-m-d H:i:s');
            
            // Préparer la requête d'insertion - ADAPTÉE À VOTRE STRUCTURE
            $sql = "INSERT INTO utilisateurs (
                nom, 
                email, 
                telephone, 
                role, 
                permissions,
                mot_de_passe, 
                date_creation, 
                date_modification, 
                statut
            ) VALUES (
                :nom,
                :email,
                :telephone,
                :role,
                :permissions,
                :mot_de_passe,
                :date_creation,
                :date_modification,
                :statut
            )";
            
            $stmt = $conn->prepare($sql);
            
            // Exécuter avec les paramètres
            $result = $stmt->execute([
                ':nom' => $nom,
                ':email' => $email,
                ':telephone' => $telephone,
                ':role' => 'admin',  // Rôle admin
                ':permissions' => $permissions,
                ':mot_de_passe' => $hash_mot_de_passe,
                ':date_creation' => $date_creation,
                ':date_modification' => $date_creation,
                ':statut' => 'actif'
            ]);
            
            if ($result) {
                $id_utilisateur = $conn->lastInsertId();
                $success = "✅ Administrateur ajouté avec succès !";
                echo "<div style='background-color: #d4edda; color: #155724; padding: 15px; margin: 15px 0; border-radius: 5px;'>
                        <strong>Succès !</strong> $success<br>
                        <strong>ID :</strong> $id_utilisateur<br>
                        <strong>Nom :</strong> $nom<br>
                        <strong>Email :</strong> $email<br>
                        <strong>Rôle :</strong> Admin<br>
                        <strong>Statut :</strong> Actif
                      </div>";
            } else {
                $erreurs[] = "Erreur lors de l'ajout à la base de données.";
            }
        }
        
        // Afficher les erreurs
        if (!empty($erreurs)) {
            echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; margin: 15px 0; border-radius: 5px;'>
                    <strong>Erreurs :</strong><ul>";
            foreach ($erreurs as $erreur) {
                echo "<li>$erreur</li>";
            }
            echo "</ul></div>";
        }
    }
    
} catch(PDOException $e) {
    die("<div style='background-color: #f8d7da; color: #721c24; padding: 15px;'>
            <strong>Erreur de connexion :</strong> " . $e->getMessage() . "<br>
            <strong>Base de données :</strong> $dbname<br>
            <strong>Vérifiez que :</strong><br>
            1. La base 'stoch_db' existe<br>
            2. La table 'utilisateurs' existe<br>
            3. Les identifiants MySQL sont corrects
         </div>");
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter un Administrateur</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"],
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
        }
        textarea {
            height: 80px;
            resize: vertical;
        }
        .btn-submit {
            background-color: #28a745;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background-color: #218838;
        }
        .required {
            color: red;
        }
        .password-strength {
            font-size: 12px;
            margin-top: 5px;
            color: #666;
        }
        .info-box {
            background-color: #e7f3fe;
            border-left: 4px solid #2196F3;
            padding: 10px;
            margin: 15px 0;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>➕ Nouvel Administrateur</h2>
        
        <div class="info-box">
            <strong>Base de données :</strong> stoch_db<br>
            <strong>Table :</strong> utilisateurs<br>
            <strong>Rôle :</strong> Admin (automatiquement défini)
        </div>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="nom">Nom complet <span class="required">*</span></label>
                <input type="text" id="nom" name="nom" 
                       value="<?php echo isset($_POST['nom']) ? htmlspecialchars($_POST['nom']) : ''; ?>"
                       required placeholder="Ex: Jean Dupont" autofocus>
            </div>
            
            <div class="form-group">
                <label for="email">Email <span class="required">*</span></label>
                <input type="email" id="email" name="email" 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                       required placeholder="exemple@domain.com">
            </div>
            
            <div class="form-group">
                <label for="telephone">Téléphone</label>
                <input type="text" id="telephone" name="telephone" 
                       value="<?php echo isset($_POST['telephone']) ? htmlspecialchars($_POST['telephone']) : ''; ?>"
                       placeholder="+243...">
            </div>
            
            <div class="form-group">
                <label for="permissions">Permissions (optionnel)</label>
                <textarea id="permissions" name="permissions" 
                    placeholder="Ex: gestion_utilisateurs, gestion_produits, rapports"><?php echo isset($_POST['permissions']) ? htmlspecialchars($_POST['permissions']) : ''; ?></textarea>
                <small>Liste séparée par des virgules</small>
            </div>
            
            <div class="form-group">
                <label for="mot_de_passe">Mot de passe <span class="required">*</span></label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" required 
                       minlength="8" onkeyup="checkPasswordStrength()">
                <div class="password-strength" id="password-strength">
                    Minimum 8 caractères (lettres, chiffres, caractères spéciaux)
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirmation">Confirmer le mot de passe <span class="required">*</span></label>
                <input type="password" id="confirmation" name="confirmation" required 
                       minlength="8" onkeyup="checkPasswordMatch()">
                <div class="password-strength" id="password-match"></div>
            </div>
            
            <button type="submit" class="btn-submit">➕ Ajouter l'administrateur</button>
        </form>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="liste_utilisateurs.php" style="color: #007bff; text-decoration: none;">
                👥 Voir la liste des utilisateurs
            </a>
            |
            <a href="http://localhost/phpmyadmin" target="_blank" style="color: #007bff; text-decoration: none;">
                📊 Ouvrir phpMyAdmin
            </a>
        </div>
    </div>
    
    <script>
        // Vérification de la force du mot de passe en temps réel
        function checkPasswordStrength() {
            const password = document.getElementById('mot_de_passe').value;
            const strength = document.getElementById('password-strength');
            
            if (password.length === 0) {
                strength.innerHTML = "Minimum 8 caractères";
                strength.style.color = "#666";
                return;
            }
            
            if (password.length < 8) {
                strength.innerHTML = "❌ Trop court (min 8 caractères)";
                strength.style.color = "red";
            } else if (password.length < 12) {
                strength.innerHTML = "⚠️ Moyen";
                strength.style.color = "orange";
            } else {
                let hasUpper = /[A-Z]/.test(password);
                let hasLower = /[a-z]/.test(password);
                let hasNumbers = /\d/.test(password);
                let hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
                
                let score = [hasUpper, hasLower, hasNumbers, hasSpecial].filter(Boolean).length;
                
                if (score >= 4) {
                    strength.innerHTML = "✅ Très fort";
                    strength.style.color = "green";
                } else if (score >= 3) {
                    strength.innerHTML = "✅ Bon";
                    strength.style.color = "green";
                } else if (score >= 2) {
                    strength.innerHTML = "⚠️ Moyen";
                    strength.style.color = "orange";
                } else {
                    strength.innerHTML = "❌ Faible";
                    strength.style.color = "red";
                }
            }
        }
        
        // Vérification de la correspondance des mots de passe
        function checkPasswordMatch() {
            const password = document.getElementById('mot_de_passe').value;
            const confirm = document.getElementById('confirmation').value;
            const match = document.getElementById('password-match');
            
            if (confirm.length === 0) {
                match.innerHTML = "";
                return;
            }
            
            if (password === confirm) {
                match.innerHTML = "✅ Les mots de passe correspondent";
                match.style.color = "green";
            } else {
                match.innerHTML = "❌ Les mots de passe ne correspondent pas";
                match.style.color = "red";
            }
        }
    </script>
</body>
</html>