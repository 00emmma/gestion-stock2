<?php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['utilisateur'])) {
    header('Location: login_user.php');
    exit();
}

// Connexion DB
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "stoch_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Erreur de connexion : " . $conn->connect_error);
}

$user_id = $_SESSION['utilisateur']['id_utilisateur'];
$message = '';
$error = '';

// GESTION DES QUESTIONS DE SÉCURITÉ
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_questions'])) {
    if ($_POST['action_questions'] == 'ajouter') {
        $question = trim($_POST['question']);
        $reponse = trim($_POST['reponse']);
        $question_perso = trim($_POST['question_perso']);
        
        // Utiliser la question personnalisée si fournie
        if (!empty($question_perso)) {
            $question = $question_perso;
        }
        
        if (empty($question) || empty($reponse)) {
            $error = "Veuillez remplir tous les champs.";
        } else {
            // Vérifier le nombre de questions existantes
            $stmt = $conn->prepare("SELECT COUNT(*) as nb_questions FROM questions_securite WHERE id_utilisateur = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $nb_questions = $result->fetch_assoc()['nb_questions'];
            
            if ($nb_questions >= 5) {
                $error = "Vous avez déjà atteint le maximum de 5 questions de sécurité.";
            } else {
                // Insérer la question
                $stmt = $conn->prepare("INSERT INTO questions_securite (id_utilisateur, question, reponse_hash) VALUES (?, ?, SHA2(?, 256))");
                $stmt->bind_param("iss", $user_id, $question, $reponse);
                
                if ($stmt->execute()) {
                    $message = "Question de sécurité ajoutée avec succès !";
                } else {
                    $error = "Erreur lors de l'ajout de la question : " . $conn->error;
                }
            }
        }
    }
}

// SUPPRIMER UNE QUESTION
if (isset($_GET['action']) && $_GET['action'] == 'supprimer_question' && isset($_GET['id_question'])) {
    $id_question = $_GET['id_question'];
    
    // Vérifier que la question appartient bien à l'utilisateur
    $stmt = $conn->prepare("DELETE FROM questions_securite WHERE id_question = ? AND id_utilisateur = ?");
    $stmt->bind_param("ii", $id_question, $user_id);
    
    if ($stmt->execute()) {
        $message = "Question supprimée avec succès !";
    } else {
        $error = "Erreur lors de la suppression de la question.";
    }
}

// MODIFIER LE MOT DE PASSE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_mdp'])) {
    $ancien_mdp = $_POST['ancien_mdp'];
    $nouveau_mdp = $_POST['nouveau_mdp'];
    $confirmer_mdp = $_POST['confirmer_mdp'];
    
    if (empty($ancien_mdp) || empty($nouveau_mdp) || empty($confirmer_mdp)) {
        $error = "Veuillez remplir tous les champs.";
    } elseif ($nouveau_mdp !== $confirmer_mdp) {
        $error = "Les nouveaux mots de passe ne correspondent pas.";
    } elseif (strlen($nouveau_mdp) < 6) {
        $error = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
    } else {
        // Vérifier l'ancien mot de passe
        $stmt = $conn->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id_utilisateur = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (password_verify($ancien_mdp, $user['mot_de_passe'])) {
            // Mettre à jour le mot de passe
            $nouveau_mdp_hash = password_hash($nouveau_mdp, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id_utilisateur = ?");
            $stmt->bind_param("si", $nouveau_mdp_hash, $user_id);
            
            if ($stmt->execute()) {
                $message = "Mot de passe modifié avec succès !";
            } else {
                $error = "Erreur lors de la modification du mot de passe.";
            }
        } else {
            $error = "L'ancien mot de passe est incorrect.";
        }
    }
}

// Récupérer les questions existantes
$stmt = $conn->prepare("SELECT id_question, question, date_creation FROM questions_securite WHERE id_utilisateur = ? ORDER BY date_creation DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$questions_existantes = $result->fetch_all(MYSQLI_ASSOC);
$nb_questions = count($questions_existantes);

// Questions prédéfinies
$questions_predefinies = [
    "Quel est le nom de votre ville de naissance ?",
    "Quel est le nom de jeune fille de votre mère ?",
    "Quel est le nom de votre premier animal de compagnie ?",
    "Quel est votre film préféré ?",
    "Quel est le nom de votre école primaire ?",
    "Quel est le métier de votre père ?",
    "Quel est votre livre préféré ?",
    "Quel est le nom de votre meilleur ami d'enfance ?",
    "Quelle est votre couleur préférée ?",
    "Quel est votre sport préféré ?"
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - STOCKFLOW</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            display: flex;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--dark);
        }

        /* Sidebar identique */
        .sidebar {
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            color: var(--dark);
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            border-right: 1px solid rgba(255, 255, 255, 0.3);
        }

        .logo {
            display: flex;
            align-items: center;
            padding: 0 20px 20px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .logo i {
            font-size: 2.2rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-right: 12px;
        }

        .logo h1 {
            font-size: 1.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome {
            padding: 20px;
            font-size: 14px;
            color: var(--gray);
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .welcome h2 {
            color: var(--primary);
            margin-bottom: 5px;
            font-weight: 600;
        }

        .nav-menu {
            list-style: none;
            margin-top: 20px;
            flex: 1;
        }

        .nav-item {
            padding: 16px 20px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--dark);
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            border-radius: 0 12px 12px 0;
            margin: 4px 0;
            position: relative;
            overflow: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transition: width 0.3s ease;
            z-index: -1;
        }

        .nav-item:hover::before,
        .nav-item.active::before {
            width: 100%;
        }

        .nav-item:hover,
        .nav-item.active {
            color: white;
            transform: translateX(8px);
        }

        .nav-item i {
            margin-right: 12px;
            font-size: 1.3rem;
            width: 24px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .nav-item:hover i,
        .nav-item.active i {
            transform: scale(1.2);
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 2px solid rgba(67, 97, 238, 0.1);
            padding: 20px;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(230, 57, 70, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(230, 57, 70, 0.4);
        }

        .logout-btn i {
            margin-right: 8px;
        }

        /* Contenu principal identique */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            min-height: 100vh;
        }

        .content-wrapper {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            min-height: calc(100vh - 60px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        /* Header identique */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 25px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .header h1 {
            color: var(--primary);
            margin: 0;
            font-size: 2.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .user-avatar:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(67, 97, 238, 0.4);
        }

        /* Cartes de statistiques identiques */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .stat-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 1.8rem;
            color: white;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .stat-content h3 {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 5px;
            line-height: 1;
        }

        .stat-description {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 500;
        }

        /* Sections paramètres */
        .settings-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.1);
        }

        .section-title {
            color: var(--primary);
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Cartes de questions */
        .questions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .question-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .question-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transform: scaleX(1);
        }

        .question-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .question-text {
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
            line-height: 1.4;
            flex: 1;
        }

        .question-date {
            color: var(--gray);
            font-size: 0.8rem;
            margin-top: 5px;
        }

        .delete-btn {
            background: linear-gradient(135deg, var(--danger), #c1121f);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            margin-left: 10px;
        }

        .delete-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(230, 57, 70, 0.3);
        }

        /* Formulaires */
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
            display: block;
        }

        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #4895ef);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 201, 240, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #f7b801);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(247, 184, 1, 0.3);
        }

        /* État vide */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--dark);
            margin-bottom: 10px;
        }

        /* Alertes */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
        }

        /* Animations */
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

        .fade-in-up {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        /* Responsive identique */
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            
            .logo h1, .welcome, .nav-item span, .logout-btn span {
                display: none;
            }
            
            .nav-item {
                justify-content: center;
                padding: 20px;
            }
            
            .nav-item i {
                margin-right: 0;
                font-size: 20px;
            }
            
            .main-content {
                margin-left: 80px;
                padding: 15px;
            }

            .content-wrapper {
                padding: 20px;
            }

            .header h1 {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stat-card {
                padding: 25px;
            }

            .stat-value {
                font-size: 2.2rem;
            }

            .questions-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .user-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Sidebar identique -->
    <div class="sidebar">
        <div class="logo">
            <i class="fas fa-warehouse"></i>
            <h1>STOCKFLOW</h1>
        </div>
        
        <div class="welcome">
            <h2>Bienvenue, <?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?></h2>
            <p>Utilisateur</p>
        </div>

        <ul class="nav-menu">
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de Bord</span>
            </a>
            <a href="produits.php" class="nav-item">
                <i class="fas fa-boxes"></i>
                <span>Produits</span>
            </a>
            <a href="commandes.php" class="nav-item">
                <i class="fas fa-shopping-cart"></i>
                <span>Commandes</span>
            </a>
            <a href="rapports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Rapports</span>
            </a>
            <a href="parametres.php" class="nav-item active">
                <i class="fas fa-cog"></i>
                <span>Paramètres</span>
            </a>
        </ul>

        <div class="sidebar-footer">
            <button class="logout-btn" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i>
                <span>Déconnexion</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-wrapper">
            <div class="header">
                <h1>Paramètres du Compte</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <p style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?></p>
                        <small style="color: var(--gray);">Utilisateur</small>
                    </div>
                </div>
            </div>

            <!-- Messages d'alerte -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show fade-in-up" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle me-2"></i>
                        <div><?php echo $message; ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show fade-in-up" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <div><?php echo $error; ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistiques rapides -->
            <div class="stats-grid">
                <div class="stat-card fade-in-up" style="animation-delay: 0.1s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Questions de Sécurité</h3>
                        <div class="stat-value"><?php echo $nb_questions; ?></div>
                        <div class="stat-description">Configurées</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.2s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--success), #4895ef);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Protection</h3>
                        <div class="stat-value"><?php echo $nb_questions > 0 ? 'Activée' : 'Désactivée'; ?></div>
                        <div class="stat-description">Sécurité du compte</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.3s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning), #f7b801);">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Dernière Connexion</h3>
                        <div class="stat-value"><?php echo date('d/m/Y'); ?></div>
                        <div class="stat-description">Aujourd'hui</div>
                    </div>
                </div>

                <div class="stat-card fade-in-up" style="animation-delay: 0.4s;">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent), #f72585);">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Statut</h3>
                        <div class="stat-value">Actif</div>
                        <div class="stat-description">Compte vérifié</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Section Questions de Sécurité -->
                <div class="col-lg-8">
                    <div class="settings-section fade-in-up" style="animation-delay: 0.5s;">
                        <div class="section-header">
                            <h3 class="section-title"><i class="fas fa-shield-alt"></i>Questions de Sécurité</h3>
                            <span class="section-badge"><?php echo $nb_questions; ?>/5 questions</span>
                        </div>

                        <!-- Information -->
                        <div class="alert alert-info mb-4">
                            <i class="fas fa-info-circle me-2"></i>
                            Les questions de sécurité vous permettent de récupérer votre compte en cas d'oubli de mot de passe.
                            Vous pouvez configurer jusqu'à 5 questions.
                        </div>

                        <!-- Formulaire d'ajout -->
                        <?php if ($nb_questions < 5): ?>
                            <div class="form-card">
                                <h5 class="mb-3">Ajouter une nouvelle question</h5>
                                <form method="POST">
                                    <input type="hidden" name="action_questions" value="ajouter">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Choisir une question prédéfinie</label>
                                        <select class="form-control" name="question" id="questionSelect" onchange="toggleCustomQuestion()">
                                            <option value="">-- Sélectionnez une question --</option>
                                            <?php foreach ($questions_predefinies as $q): ?>
                                                <option value="<?php echo htmlspecialchars($q); ?>"><?php echo htmlspecialchars($q); ?></option>
                                            <?php endforeach; ?>
                                            <option value="custom">-- Personnalisée --</option>
                                        </select>
                                    </div>

                                    <div class="form-group" id="customQuestionDiv" style="display: none;">
                                        <label class="form-label">Votre question personnalisée</label>
                                        <input type="text" class="form-control" name="question_perso" 
                                               placeholder="Formulez votre propre question de sécurité">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Votre réponse</label>
                                        <input type="text" class="form-control" name="reponse" required
                                               placeholder="Réponse précise et mémorable">
                                        <small class="text-muted">La réponse est sensible à la casse et aux accents.</small>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i>Ajouter la question
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Vous avez atteint le maximum de 5 questions de sécurité.
                            </div>
                        <?php endif; ?>

                        <!-- Liste des questions existantes -->
                        <h5 class="mb-3">Vos questions de sécurité</h5>
                        
                        <?php if (empty($questions_existantes)): ?>
                            <div class="empty-state">
                                <i class="fas fa-shield-alt"></i>
                                <h4>Aucune question configurée</h4>
                                <p>Ajoutez votre première question de sécurité.</p>
                            </div>
                        <?php else: ?>
                            <div class="questions-grid">
                                <?php foreach ($questions_existantes as $question): ?>
                                    <div class="question-card">
                                        <div class="question-header">
                                            <div>
                                                <div class="question-text"><?php echo htmlspecialchars($question['question']); ?></div>
                                                <div class="question-date">
                                                    Ajoutée le <?php echo date('d/m/Y à H:i', strtotime($question['date_creation'])); ?>
                                                </div>
                                            </div>
                                            <a href="parametres.php?action=supprimer_question&id_question=<?php echo $question['id_question']; ?>" 
                                               class="delete-btn"
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette question ?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Test de sécurité -->
                        <div class="form-card">
                            <h5 class="mb-3">Tester la sécurité</h5>
                            <p class="text-muted mb-3">
                                Vérifiez que vous vous souvenez bien de vos réponses en testant la procédure de récupération.
                            </p>
                            <a href="verif_questions.php" class="btn btn-warning">
                                <i class="fas fa-check-shield me-2"></i>Tester mes réponses
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Section Informations et Mot de Passe -->
                <div class="col-lg-4">
                    <!-- Modification du Mot de Passe -->
                    <div class="settings-section fade-in-up" style="animation-delay: 0.6s;">
                        <div class="section-header">
                            <h3 class="section-title"><i class="fas fa-lock"></i>Mot de Passe</h3>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action_mdp" value="modifier">
                            
                            <div class="form-group">
                                <label class="form-label">Ancien mot de passe</label>
                                <input type="password" class="form-control" name="ancien_mdp" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Nouveau mot de passe</label>
                                <input type="password" class="form-control" name="nouveau_mdp" required minlength="6">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Confirmer le mot de passe</label>
                                <input type="password" class="form-control" name="confirmer_mdp" required minlength="6">
                            </div>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Le mot de passe doit contenir au moins 6 caractères.
                            </div>

                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-save me-2"></i>Modifier le mot de passe
                            </button>
                        </form>
                    </div>

                    <!-- Informations du Compte -->
                    <div class="settings-section fade-in-up" style="animation-delay: 0.7s;">
                        <div class="section-header">
                            <h3 class="section-title"><i class="fas fa-user"></i>Informations du Compte</h3>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Nom complet</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['utilisateur']['nom']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Adresse email</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($_SESSION['utilisateur']['email']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Rôle</label>
                            <input type="text" class="form-control" value="Utilisateur" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Statut du compte</label>
                            <input type="text" class="form-control" value="Actif" readonly>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Date de création</label>
                            <input type="text" class="form-control" value="<?php echo date('d/m/Y'); ?>" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Fonction de déconnexion identique
        function logout() {
            Swal.fire({
                title: 'Déconnexion',
                text: 'Êtes-vous sûr de vouloir vous déconnecter ?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Oui, déconnecter',
                cancelButtonText: 'Annuler',
                background: 'rgba(255, 255, 255, 0.95)',
                backdrop: 'rgba(67, 97, 238, 0.1)'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }

        // Toggle question personnalisée
        function toggleCustomQuestion() {
            const select = document.getElementById('questionSelect');
            const customDiv = document.getElementById('customQuestionDiv');
            
            if (select.value === 'custom') {
                customDiv.style.display = 'block';
            } else {
                customDiv.style.display = 'none';
            }
        }

        // Animation au défilement identique
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationPlayState = 'running';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in-up').forEach(el => {
            el.style.animationPlayState = 'paused';
            observer.observe(el);
        });

        // Confirmation pour suppression
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Êtes-vous sûr de vouloir supprimer cette question ?')) {
                    e.preventDefault();
                }
            });
        });

        // Auto-dismiss alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
<?php
$conn->close();
?>