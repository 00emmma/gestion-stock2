<?php
session_start();

// Connexion DB
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "stoch_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Erreur de connexion : " . $conn->connect_error);
}

$token = $_GET['token'] ?? '';
$message = '';
$error = '';

// Vérifier le token
if (!empty($token)) {
    $stmt = $conn->prepare("SELECT id_utilisateur, token_expiration FROM utilisateurs WHERE reset_token = ? AND token_expiration > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $user_id = $user['id_utilisateur'];
        $_SESSION['reset_user_id'] = $user_id;
    } else {
        $error = "Token invalide ou expiré.";
    }
} else {
    $error = "Aucun token fourni.";
}

// Traitement du nouveau mot de passe
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['reset_user_id'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Veuillez remplir tous les champs.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Les mots de passe ne correspondent pas.";
    } elseif (strlen($new_password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
    } else {
        // Hasher le nouveau mot de passe
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Mettre à jour le mot de passe et effacer le token
        $stmt = $conn->prepare("UPDATE utilisateurs SET mot_de_passe = ?, reset_token = NULL, token_expiration = NULL WHERE id_utilisateur = ?");
        $stmt->bind_param("si", $password_hash, $_SESSION['reset_user_id']);
        
        if ($stmt->execute()) {
            $message = "✅ Mot de passe réinitialisé avec succès !";
            // Nettoyer la session
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_token']);
        } else {
            $error = "Erreur lors de la réinitialisation : " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation du Mot de Passe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .reset-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-width: 500px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0"><i class="fas fa-key me-2"></i>Nouveau Mot de Passe</h4>
        </div>
        <div class="card-body p-4">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= $error ?>
                </div>
                <div class="text-center">
                    <a href="forgot_password.php" class="btn btn-primary">Réessayer</a>
                </div>
            <?php elseif ($message): ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle me-2"></i><?= $message ?>
                    <div class="mt-3">
                        <a href="login_user.php" class="btn btn-success">Se connecter</a>
                    </div>
                </div>
            <?php elseif (!empty($token) && isset($_SESSION['reset_user_id'])): ?>
                <div class="text-center mb-4">
                    <i class="fas fa-lock fa-3x text-success mb-3"></i>
                    <h3>Nouveau Mot de Passe</h3>
                    <p class="text-muted">Choisissez votre nouveau mot de passe</p>
                </div>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Nouveau mot de passe</label>
                        <input type="password" class="form-control" name="new_password" required 
                               placeholder="Minimum 6 caractères" minlength="6">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Confirmer le mot de passe</label>
                        <input type="password" class="form-control" name="confirm_password" required 
                               placeholder="Répétez le mot de passe">
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Le mot de passe doit contenir au moins 6 caractères.
                    </div>

                    <button type="submit" class="btn btn-success w-100 mb-3">
                        <i class="fas fa-save me-2"></i>Réinitialiser le mot de passe
                    </button>
                    
                    <a href="login_user.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-arrow-left me-2"></i>Retour à la connexion
                    </a>
                </form>
            <?php else: ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Lien de réinitialisation invalide.
                    <div class="mt-2">
                        <a href="forgot_password.php" class="btn btn-primary">Demander un nouveau lien</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>