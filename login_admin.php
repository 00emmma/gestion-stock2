<?php
// login_admin.php - VERSION DESIGN ÉPURÉ
session_start();

// Rediriger vers le dashboard si déjà connecté en tant qu'admin
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin') {
    header("Location: Views/admin/dashboard.php");
    exit();
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $mot_de_passe = $_POST['mot_de_passe'];

    // Connexion à la base de données
    $conn = new mysqli("localhost", "root", "", "stoch_db");

    if ($conn->connect_error) {
        die("Erreur de connexion: " . $conn->connect_error);
    }

    // Requête principale
    $stmt = $conn->prepare("SELECT * FROM utilisateurs WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Vérifier le statut
        if ($user['statut'] !== 'actif') {
            $error = "Compte désactivé. Contactez l'administrateur.";
        }
        // Vérifier le rôle
        else if ($user['role'] !== 'admin') {
            $error = "Accès refusé : Cet email n'est pas associé à un compte administrateur.";
        }
        // Vérifier le mot de passe
        else if (password_verify($mot_de_passe, $user['mot_de_passe'])) {
            // Connexion réussie
            $_SESSION['utilisateur'] = $user;
            $_SESSION['user_id'] = $user['id_utilisateur'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            
            // Redirection vers le dashboard admin
            header("Location: Views/admin/dashboard.php");
            exit();
        } else {
            $error = "Mot de passe incorrect.";
        }
    } else {
        $error = "Email non trouvé dans la base de données.";
    }

    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion Administrateur - STOCKFLOW</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #dc3545;
            --primary-dark: #c82333;
            --primary-light: #e35d6a;
            --dark: #212529;
            --light: #f8f9fa;
            --border: #e9ecef;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .login-header {
            background: var(--primary);
            color: white;
            padding: 40px 32px;
            text-align: center;
        }

        .admin-icon {
            font-size: 2.5rem;
            margin-bottom: 16px;
            display: block;
        }

        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .login-subtitle {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .login-body {
            padding: 40px 32px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
            display: block;
            font-size: 0.9rem;
        }

        .input-container {
            position: relative;
        }

        .form-control {
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 1rem;
            color: var(--dark);
            transition: all 0.2s ease;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
            outline: none;
        }

        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            cursor: pointer;
        }

        .btn-login {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 14px 24px;
            font-size: 1rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.2s ease;
            margin-top: 8px;
        }

        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .back-link {
            color: #6c757d;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 24px;
            transition: color 0.2s ease;
        }

        .back-link:hover {
            color: var(--primary);
        }

        .alert {
            border-radius: 8px;
            border: none;
            padding: 16px;
            margin-bottom: 24px;
            background: #f8d7da;
            color: #721c24;
        }

        .login-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            font-size: 0.9rem;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #6c757d;
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }

            .login-header {
                padding: 32px 24px;
            }

            .login-body {
                padding: 32px 24px;
            }

            .admin-icon {
                font-size: 2rem;
            }

            .login-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-lock admin-icon"></i>
                <h1>Administration</h1>
                <p class="login-subtitle">Accès sécurisé au panneau de contrôle</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="loginForm">
                    <div class="form-group">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email Administrateur
                        </label>
                        <div class="input-container">
                            <input type="email" 
                                   class="form-control" 
                                   id="email" 
                                   name="email" 
                                   placeholder="admin@entreprise.com" 
                                   required
                                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="mot_de_passe" class="form-label">
                            <i class="fas fa-key me-2"></i>Mot de passe
                        </label>
                        <div class="input-container">
                            <input type="password" 
                                   class="form-control" 
                                   id="mot_de_passe" 
                                   name="mot_de_passe" 
                                   placeholder="Votre mot de passe" 
                                   required>
                            <i class="fas fa-eye input-icon" onclick="togglePassword()" id="toggleIcon"></i>
                        </div>
                    </div>

                    <div class="login-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember">
                            Se souvenir de moi
                        </label>
                        <a href="forgot_password.php" class="forgot-password" onclick="showForgotPassword()">
                            Mot de passe oublié ?
                        </a>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i>Se connecter
                    </button>
                    
                    <div class="text-center">
                        <a href="../index.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Retour à l'accueil
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('mot_de_passe');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        function showForgotPassword() {
            alert('Veuillez contacter le super administrateur pour réinitialiser votre mot de passe.');
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('mot_de_passe').value;
            
            if (!email || !password) {
                e.preventDefault();
                alert('Veuillez remplir tous les champs obligatoires.');
                return false;
            }
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Veuillez entrer une adresse email valide.');
                return false;
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>