<?php
// index.php
session_start();

// Vérifier si déconnexion récente
if (isset($_GET['logout']) && isset($_GET['user'])) {
    $logout_message = "Au revoir " . htmlspecialchars($_GET['user']) . " ! Vous avez été déconnecté avec succès.";
}

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header("Location: Views/admin/dashboard.php");
    } else {
        header("Location: Views/user/dashboard.php");
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accueil - STOCKFLOW</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --accent: #f72585;
            --success: #4cc9f0;
            --dark: #212529;
            --light: #f8f9fa;
            --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-admin: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            --gradient-user: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: var(--gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Effets de fond animés */
        .background-effects {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .floating-shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        /* Container principal */
        .main-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 500px;
            animation: slideIn 0.8s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Carte principale */
        .welcome-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-align: center;
        }

        /* En-tête */
        .logo-section {
            margin-bottom: 40px;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .logo-icon {
            font-size: 3rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: bounce 2s ease infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }

        .logo-text {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
        }

        .welcome-title {
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 5px;
            font-weight: 600;
        }

        .welcome-subtitle {
            color: #6c757d;
            font-size: 0.95rem;
        }

        /* Boutons de connexion */
        .auth-buttons {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin: 40px 0;
        }

        .auth-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            padding: 18px 30px;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .auth-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s ease;
        }

        .auth-btn:hover::before {
            left: 100%;
        }

        .auth-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .btn-admin {
            background: var(--gradient-admin);
            color: white;
        }

        .btn-user {
            background: var(--gradient-user);
            color: white;
        }

        .btn-icon {
            font-size: 1.3rem;
        }

        /* Alertes */
        .alert-custom {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(76, 201, 240, 0.3);
        }

        /* Footer */
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }

        .version {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .features {
            display: flex;
            justify-content: center;
            gap: 25px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6c757d;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .feature-icon {
            color: var(--primary);
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 576px) {
            .welcome-card {
                padding: 40px 25px;
                margin: 10px;
            }

            .logo {
                flex-direction: column;
                gap: 10px;
            }

            .logo-icon {
                font-size: 2.5rem;
            }

            .logo-text {
                font-size: 2rem;
            }

            .auth-btn {
                padding: 16px 25px;
                font-size: 1rem;
            }

            .features {
                flex-direction: column;
                gap: 15px;
                align-items: center;
            }
        }

        /* Effets de hover supplémentaires */
        .auth-btn:active {
            transform: translateY(-1px);
        }

        /* Animation des éléments */
        .animate-item {
            animation: fadeInUp 0.6s ease forwards;
            opacity: 0;
        }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Effets de fond -->
    <div class="background-effects" id="backgroundEffects"></div>

    <!-- Container principal -->
    <div class="main-container">
        <div class="welcome-card">
            <!-- Logo et titre -->
            <div class="logo-section">
                <div class="logo">
                    <i class="fas fa-warehouse logo-icon"></i>
                    <h1 class="logo-text">GESTION STOCK</h1>
                </div>

            </div>

            <!-- Message de déconnexion -->
            <?php if (isset($logout_message)): ?>
                <div class="alert-custom animate-item" style="animation-delay: 0.2s">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= $logout_message ?>
                </div>
            <?php endif; ?>

            <!-- Boutons d'authentification -->
            <div class="auth-buttons">
                <a href="login_admin.php" class="auth-btn btn-admin animate-item" style="animation-delay: 0.3s">
                    <i class="fas fa-crown btn-icon"></i>
                    <span>Connexion Administrateur</span>
                </a>
                
                <a href="login_user.php" class="auth-btn btn-user animate-item" style="animation-delay: 0.4s">
                    <i class="fas fa-user-shield btn-icon"></i>
                    <span>Connexion Utilisateur</span>
                </a>
            </div>

        </div>
    </div>

    <script>
        // Création des formes flottantes
        function createFloatingShapes() {
            const container = document.getElementById('backgroundEffects');
            const shapesCount = 8;

            for (let i = 0; i < shapesCount; i++) {
                const shape = document.createElement('div');
                shape.className = 'floating-shape';
                
                // Taille aléatoire
                const size = Math.random() * 100 + 50;
                shape.style.width = `${size}px`;
                shape.style.height = `${size}px`;
                
                // Position aléatoire
                shape.style.left = `${Math.random() * 100}%`;
                shape.style.top = `${Math.random() * 100}%`;
                
                // Délai d'animation aléatoire
                shape.style.animationDelay = `${Math.random() * 6}s`;
                
                // Opacité aléatoire
                shape.style.opacity = Math.random() * 0.1 + 0.05;
                
                container.appendChild(shape);
            }
        }

        // Animation des éléments au chargement
        document.addEventListener('DOMContentLoaded', function() {
            createFloatingShapes();
            
            // Animation séquentielle des éléments
            const items = document.querySelectorAll('.animate-item');
            items.forEach((item, index) => {
                item.style.animationDelay = `${0.2 + (index * 0.1)}s`;
            });
        });

        // Effet de hover amélioré pour les boutons
        document.querySelectorAll('.auth-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px) scale(1.02)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Effet de clic
        document.querySelectorAll('.auth-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                // Ajouter un effet de loading visuel
                const originalContent = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Chargement...';
                this.style.pointerEvents = 'none';
                
                setTimeout(() => {
                    this.innerHTML = originalContent;
                    this.style.pointerEvents = 'auto';
                }, 1000);
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>