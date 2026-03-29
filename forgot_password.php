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

$etape = $_GET['etape'] ?? 'email';
$message = '';
$error = '';

// ÉTAPE 1: Vérification email
if ($_SERVER["REQUEST_METHOD"] == "POST" && $etape == 'email') {
    $email = trim($_POST['email']);
    
    $stmt = $conn->prepare("SELECT id_utilisateur, nom, email FROM utilisateurs WHERE email = ? AND statut = 'actif'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $_SESSION['reset_user_id'] = $user['id_utilisateur'];
        $_SESSION['reset_user_email'] = $user['email'];
        $_SESSION['reset_user_nom'] = $user['nom'];
        
        // Vérifier si l'utilisateur a configuré des questions
        $stmt_questions = $conn->prepare("SELECT COUNT(*) as nb_questions FROM questions_securite WHERE id_utilisateur = ?");
        $stmt_questions->bind_param("i", $user['id_utilisateur']);
        $stmt_questions->execute();
        $result_questions = $stmt_questions->get_result();
        $nb_questions = $result_questions->fetch_assoc()['nb_questions'];
        
        if ($nb_questions > 0) {
            header('Location: forgot_password.php?etape=questions');
            exit();
        } else {
            $error = "Aucune question de sécurité configurée. Contactez l'administrateur.";
        }
    } else {
        $error = "Aucun compte actif trouvé avec cet email.";
    }
}

// ÉTAPE 2: Réponses aux questions - VERSION FINALE CORRIGÉE
if ($_SERVER["REQUEST_METHOD"] == "POST" && $etape == 'questions') {
    $user_id = $_SESSION['reset_user_id'];
    
    // Récupérer les questions
    $stmt = $conn->prepare("SELECT id_question, question, reponse_hash FROM questions_securite WHERE id_utilisateur = ? ORDER BY id_question");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $questions = $result->fetch_all(MYSQLI_ASSOC);
    
    $toutes_correctes = true;
    $messages_erreur = [];
    
    foreach ($questions as $question) {
        // CORRECTION : Vérifier si la réponse existe avant de la traiter
        $field_name = 'reponse_' . $question['id_question'];
        $reponse_utilisateur = isset($_POST[$field_name]) ? trim($_POST[$field_name]) : '';
        
        if (empty($reponse_utilisateur)) {
            $toutes_correctes = false;
            $messages_erreur[] = "Veuillez répondre à la question : '" . $question['question'] . "'";
            continue;
        }
        
        // Vérifier avec MySQL SHA2()
        $stmt_check = $conn->prepare("SELECT SHA2(?, 256) as hash_calculé");
        $stmt_check->bind_param("s", $reponse_utilisateur);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $hash_calculé = $result_check->fetch_assoc()['hash_calculé'];
        
        if ($hash_calculé !== $question['reponse_hash']) {
            $toutes_correctes = false;
            $messages_erreur[] = "La réponse à la question '" . $question['question'] . "' est incorrecte.";
        }
    }
    
    if ($toutes_correctes) {
        // Générer un token de réinitialisation
        $token = bin2hex(random_bytes(32));
        $expiration = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        try {
            // Vérifier si les colonnes existent avant de tenter la mise à jour
            $stmt_check_columns = $conn->prepare("SHOW COLUMNS FROM utilisateurs LIKE 'reset_token'");
            $stmt_check_columns->execute();
            $result_columns = $stmt_check_columns->get_result();
            
            if ($result_columns->num_rows > 0) {
                // Les colonnes existent, on peut faire la mise à jour
                $stmt = $conn->prepare("UPDATE utilisateurs SET reset_token = ?, token_expiration = ? WHERE id_utilisateur = ?");
                $stmt->bind_param("ssi", $token, $expiration, $user_id);
                
                if ($stmt->execute()) {
                    $_SESSION['reset_token'] = $token;
                    header('Location: reset_password.php?token=' . $token);
                    exit();
                } else {
                    $error = "Erreur lors de la génération du token : " . $conn->error;
                }
            } else {
                // Les colonnes n'existent pas
                $error = "Configuration manquante. Les colonnes de réinitialisation n'existent pas dans la base de données.";
            }
        } catch (Exception $e) {
            $error = "Erreur de base de données : " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $messages_erreur);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de Passe Oublié</title>
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
        .recovery-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-width: 500px;
            width: 100%;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 15px 15px 0 0;
        }
        .step {
            text-align: center;
            flex: 1;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .step.active .step-number {
            background: #4361ee;
            color: white;
        }
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
    </style>
</head>
<body>
    <div class="recovery-card">
        <div class="step-indicator">
            <div class="step <?= $etape == 'email' ? 'active' : 'completed' ?>">
                <div class="step-number">1</div>
                <small>Email</small>
            </div>
            <div class="step <?= $etape == 'questions' ? 'active' : ($etape == 'reset' ? 'completed' : '') ?>">
                <div class="step-number">2</div>
                <small>Questions</small>
            </div>
            <div class="step <?= $etape == 'reset' ? 'active' : '' ?>">
                <div class="step-number">3</div>
                <small>Nouveau mot de passe</small>
            </div>
        </div>

        <div class="p-4">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= $error ?>
                </div>
            <?php endif; ?>

            <?php if ($etape == 'email'): ?>
                <div class="text-center mb-4">
                    <i class="fas fa-key fa-3x text-primary mb-3"></i>
                    <h3>Mot de Passe Oublié</h3>
                    <p class="text-muted">Entrez votre email pour commencer la récupération</p>
                </div>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Adresse Email</label>
                        <input type="email" class="form-control" name="email" required 
                               placeholder="votre@email.com" value="user@test.com">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-3">
                        <i class="fas fa-arrow-right me-2"></i>Continuer
                    </button>
                    <a href="login_user.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-arrow-left me-2"></i>Retour à la connexion
                    </a>
                </form>

            <?php elseif ($etape == 'questions' && isset($_SESSION['reset_user_id'])): ?>
                <?php
                $user_id = $_SESSION['reset_user_id'];
                $stmt = $conn->prepare("SELECT id_question, question FROM questions_securite WHERE id_utilisateur = ? ORDER BY id_question");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $questions = $result->fetch_all(MYSQLI_ASSOC);
                ?>

                <div class="text-center mb-4">
                    <i class="fas fa-shield-alt fa-3x text-warning mb-3"></i>
                    <h3>Vérification de Sécurité</h3>
                    <p class="text-muted">Répondez à vos questions de sécurité</p>
                    <div class="alert alert-info mt-3">
                        <small><strong>Réponses de test :</strong> Utilisez "paris", "medor" et "dubois"</small>
                    </div>
                </div>

                <form method="POST">
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                Question <?= $index + 1 ?>: <?= htmlspecialchars($question['question']) ?>
                            </label>
                            <input type="text" class="form-control" 
                                   name="reponse_<?= $question['id_question'] ?>" 
                                   placeholder="Votre réponse"
                                   required
                                   value="<?= 
                                       $index == 0 ? 'paris' : 
                                       ($index == 1 ? 'medor' : 'dubois')
                                   ?>">
                        </div>
                    <?php endforeach; ?>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important :</strong> Les réponses sont sensibles à la casse. Répondez exactement comme indiqué.
                    </div>

                    <button type="submit" class="btn btn-primary w-100 mb-3">
                        <i class="fas fa-check me-2"></i>Vérifier les réponses
                    </button>
                    <a href="forgot_password.php?etape=email" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-arrow-left me-2"></i>Retour
                    </a>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>