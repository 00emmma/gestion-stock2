<?php
// login_user.php
session_start();
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $mot_de_passe = $_POST['mot_de_passe'];

    // Connexion à la base de données
    $conn = new mysqli("localhost", "root", "", "stoch_db");

    if ($conn->connect_error) {
        die("Erreur de connexion: " . $conn->connect_error);
    }

    // Vérification spécifique pour les utilisateurs normaux
    $stmt = $conn->prepare("SELECT * FROM utilisateurs WHERE email = ? AND role = 'user' AND statut = 'actif'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($mot_de_passe, $user['mot_de_passe'])) {
            // Stockage des informations de session
            $_SESSION['utilisateur'] = $user;
            $_SESSION['user_id'] = $user['id_utilisateur'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            
            // Redirection vers le dashboard utilisateur
            header("Location: Views/user/dashboard.php");
            exit();
        } else {
            $error = "Mot de passe incorrect.";
        }
    } else {
        $error = "Compte utilisateur introuvable ou compte désactivé.";
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
    <title>Connexion Utilisateur - STOCKFLOW</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --accent: #f72585;
            --success: #4cc9f0;
            --warning: #f7b801;
            --danger: #e63946;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Effets de particules en arrière-plan */
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { 
                transform: translateY(0px) translateX(0px) rotate(0deg);
                opacity: 0.3;
            }
            25% { 
                transform: translateY(-20px) translateX(10px) rotate(90deg);
                opacity: 0.6;
            }
            50% { 
                transform: translateY(-10px) translateX(20px) rotate(180deg);
                opacity: 0.8;
            }
            75% { 
                transform: translateY(-30px) translateX(-10px) rotate(270deg);
                opacity: 0.6;
            }
        }

        /* Container principal */
        .login-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 440px;
        }

        /* Carte de connexion */
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        /* En-tête */
        .login-header {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .logo-icon {
            font-size: 2.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-text {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .login-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
        }

        .login-subtitle {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Formulaire */
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 10px;
            display: block;
            font-size: 0.95rem;
        }

        .input-group {
            position: relative;
        }

        .form-control {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 1rem;
            color: var(--dark);
            transition: all 0.3s ease;
            height: 52px;
        }

        .form-control:focus {
            background: white;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            transform: translateY(-1px);
        }

        .form-control::placeholder {
            color: #adb5bd;
        }

        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.1rem;
        }

        /* Bouton de connexion */
        .login-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 16px 32px;
            font-size: 1.1rem;
            font-weight: 700;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-top: 10px;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s ease;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        /* Lien de retour */
        .back-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            margin-top: 25px;
            padding: 12px;
            border-radius: 10px;
        }

        .back-link:hover {
            background: rgba(67, 97, 238, 0.1);
            color: var(--secondary);
            transform: translateX(-5px);
        }

        /* Alertes */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            margin-bottom: 25px;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .alert-danger {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
        }

        /* Options supplémentaires */
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
            gap: 8px;
            color: var(--gray);
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--secondary);
        }

        /* Animation d'entrée */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-card {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-card {
                padding: 30px 25px;
                margin: 10px;
            }

            .login-header {
                margin-bottom: 25px;
            }

            .logo-text {
                font-size: 1.5rem;
            }

            .login-title {
                font-size: 1.3rem;
            }

            .form-control {
                padding: 12px 14px;
                height: 48px;
            }

            .login-btn {
                padding: 14px 28px;
                font-size: 1rem;
            }
        }

        /* Mode sombre */
        @media (prefers-color-scheme: dark) {
            .login-card {
                background: rgba(45, 55, 72, 0.95);
                color: #e2e8f0;
            }

            .form-control {
                background: rgba(74, 85, 104, 0.9);
                border-color: #4a5568;
                color: #e2e8f0;
            }

            .form-control:focus {
                background: #4a5568;
            }

            .form-label {
                color: #e2e8f0;
            }

            .login-title {
                color: #e2e8f0;
            }

            .login-subtitle {
                color: #a0aec0;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Particules d'arrière-plan -->
    <div class="particles" id="particles"></div>

    <!-- Container principal -->
    <div class="login-container">
        <div class="login-card">
            <!-- En-tête -->
            <div class="login-header">
                <div class="logo">
                    <i class="fas fa-warehouse logo-icon"></i>
                    <h1 class="logo-text">GESTION STOCK</h1>
                </div>

            </div>

            <!-- Messages d'erreur -->
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Formulaire -->
            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope me-2"></i>Email Utilisateur
                    </label>
                    <div class="input-group">
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               placeholder="utilisateur@entreprise.com" 
                               required
                               autocomplete="email">
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="mot_de_passe" class="form-label">
                        <i class="fas fa-lock me-2"></i>Mot de passe
                    </label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control" 
                               id="mot_de_passe" 
                               name="mot_de_passe" 
                               placeholder="••••••••" 
                               required
                               autocomplete="current-password">
                        <i class="fas fa-key input-icon"></i>
                    </div>
                </div>

                <!-- Options supplémentaires -->
                <div class="login-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember">
                        Se souvenir de moi
                    </label>
                    <a href="forgot_password.php" class="forgot-password" onclick="showForgotPassword()">
                        Mot de passe oublié ?
                    </a>
                </div>

                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt me-2"></i>
                    Se connecter
                </button>
            </form>

            <!-- Lien de retour -->
            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Retour à l'accueil
            </a>
        </div>
    </div>

    <script>
        // Création des particules d'arrière-plan
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 15;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
                // Taille aléatoire entre 4px et 8px
                const size = Math.random() * 4 + 4;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Position aléatoire
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.top = `${Math.random() * 100}%`;
                
                // Délai d'animation aléatoire
                particle.style.animationDelay = `${Math.random() * 6}s`;
                
                particlesContainer.appendChild(particle);
            }
        }

        // Fonction pour le mot de passe oublié
        function showForgotPassword() {
            Swal.fire({
                title: 'Mot de passe oublié ?',
                html: `
                    <p>Contactez votre administrateur pour réinitialiser votre mot de passe.</p>
                    <div class="text-start mt-3">
                        <strong>Email de contact :</strong><br>
                        <i class="fas fa-envelope me-2"></i>admin@stockflow.com
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'Compris',
                confirmButtonColor: '#4361ee',
                background: 'rgba(255, 255, 255, 0.95)',
                backdrop: 'rgba(67, 97, 238, 0.1)'
            });
        }

        // Validation du formulaire
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('mot_de_passe').value;

            if (!email || !password) {
                e.preventDefault();
                Swal.fire({
                    title: 'Champs manquants',
                    text: 'Veuillez remplir tous les champs obligatoires.',
                    icon: 'warning',
                    confirmButtonColor: '#4361ee',
                    background: 'rgba(255, 255, 255, 0.95)'
                });
            }
        });

        // Effet de focus sur les champs
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });

            input.addEventListener('blur', function() {
                if (!this.value) {
                    this.parentElement.classList.remove('focused');
                }
            });
        });

        // Animation d'entrée pour les éléments
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            
            // Ajout d'un délai d'apparition pour les éléments
            const elements = document.querySelectorAll('.form-group, .login-btn, .back-link');
            elements.forEach((el, index) => {
                el.style.animationDelay = `${0.3 + (index * 0.1)}s`;
                el.style.animation = 'fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                el.style.opacity = '0';
            });
        });

        // Mode sombre automatique
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.body.classList.add('dark-mode');
        }

        // Gestion du changement de mode sombre
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            if (e.matches) {
                document.body.classList.add('dark-mode');
            } else {
                document.body.classList.remove('dark-mode');
            }
        });
    </script>
</body>
</html>