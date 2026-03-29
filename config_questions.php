<?php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['utilisateur'])) {
    header('Location: login_user.php');
    exit();
}

// Connexion à la base de données
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

// Récupérer les questions existantes
$questions_existantes = [];
$stmt = $conn->prepare("SELECT id_question, question FROM questions_securite WHERE id_utilisateur = ? ORDER BY id_question");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $questions_existantes[] = $row;
}

// Gestion du formulaire
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $questions = [];
    $reponses = [];
    
    for ($i = 1; $i <= 3; $i++) {
        $questions[] = trim($_POST['question' . $i]);
        $reponses[] = trim($_POST['reponse' . $i]);
    }

    // Validation
    $valid = true;
    foreach ($questions as $index => $question) {
        if (empty($question) || empty($reponses[$index])) {
            $error = "Veuillez remplir toutes les questions et réponses.";
            $valid = false;
            break;
        }
    }

    // Vérifier les doublons
    if ($valid && count(array_unique($questions)) < 3) {
        $error = "Les questions doivent être différentes.";
        $valid = false;
    }

    if ($valid) {
        try {
            $conn->begin_transaction();

            // Supprimer les anciennes questions
            $stmt_delete = $conn->prepare("DELETE FROM questions_securite WHERE id_utilisateur = ?");
            $stmt_delete->bind_param("i", $user_id);
            $stmt_delete->execute();

            // Insérer les nouvelles
            $stmt_insert = $conn->prepare("INSERT INTO questions_securite (id_utilisateur, question, reponse_hash) VALUES (?, ?, ?)");
            
            foreach ($questions as $index => $question) {
                $reponse_hash = password_hash(strtolower(trim($reponses[$index])), PASSWORD_DEFAULT);
                $stmt_insert->bind_param("iss", $user_id, $question, $reponse_hash);
                $stmt_insert->execute();
            }

            $conn->commit();
            $message = "✅ Vos questions de sécurité ont été enregistrées !";

        } catch (Exception $e) {
            $conn->rollback();
            $error = "❌ Erreur : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Questions de Sécurité</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            font-family: 'Segoe UI', sans-serif;
        }
        
        .security-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-width: 800px;
            margin: 0 auto;
        }
        
        .security-header {
            background: linear-gradient(135deg, #4361ee, #7209b7);
            color: white;
            padding: 30px;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        
        .question-section {
            padding: 30px;
        }
        
        .question-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .question-card:hover {
            border-color: #4361ee;
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.1);
        }
        
        .question-number {
            background: #4361ee;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .suggestions {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .suggestion-item {
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 5px;
            margin: 2px 0;
        }
        
        .suggestion-item:hover {
            background: #4361ee;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="security-card">
            <!-- En-tête -->
            <div class="security-header">
                <div class="d-flex align-items-center justify-content-center mb-3">
                    <i class="fas fa-shield-alt fa-2x me-3"></i>
                    <h2 class="mb-0">Questions de Sécurité</h2>
                </div>
                <p class="mb-0">Protégez votre compte avec des questions personnelles</p>
            </div>

            <!-- Messages -->
            <div class="px-4 pt-4">
                <?php if ($message): ?>
                    <div class="alert alert-success d-flex align-items-center">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= $message ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= $error ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Formulaire -->
            <div class="question-section">
                <form method="POST" id="securityForm">
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                        <div class="question-card">
                            <div class="d-flex align-items-center mb-3">
                                <span class="question-number"><?= $i ?></span>
                                <h5 class="mb-0">Question <?= $i ?></h5>
                            </div>
                            
                            <!-- Question -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Votre question</label>
                                <input type="text" 
                                       class="form-control" 
                                       name="question<?= $i ?>" 
                                       placeholder="Ex: Quel est le nom de votre animal de compagnie ?"
                                       value="<?= isset($questions_existantes[$i-1]['question']) ? htmlspecialchars($questions_existantes[$i-1]['question']) : '' ?>"
                                       required>
                                
                                <!-- Suggestions rapides -->
                                <div class="suggestions mt-2">
                                    <small class="text-muted d-block mb-2">Suggestions :</small>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="suggestion-item badge bg-light text-dark" onclick="this.parentElement.previousElementSibling.value = 'Quel est le nom de votre animal de compagnie ?'">Animal de compagnie</span>
                                        <span class="suggestion-item badge bg-light text-dark" onclick="this.parentElement.previousElementSibling.value = 'Quelle est votre ville de naissance ?'">Ville de naissance</span>
                                        <span class="suggestion-item badge bg-light text-dark" onclick="this.parentElement.previousElementSibling.value = 'Quel est votre film préféré ?'">Film préféré</span>
                                        <span class="suggestion-item badge bg-light text-dark" onclick="this.parentElement.previousElementSibling.value = 'Quel est le nom de votre meilleur ami ?'">Meilleur ami</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Réponse -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Votre réponse</label>
                                <input type="text" 
                                       class="form-control" 
                                       name="reponse<?= $i ?>" 
                                       placeholder="Votre réponse secrète"
                                       minlength="2"
                                       required>
                                <div class="form-text">
                                    <i class="fas fa-info-circle"></i> Cette réponse sera utilisée pour vérifier votre identité
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <!-- Informations -->
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb me-2"></i>Conseils importants</h6>
                        <ul class="mb-0">
                            <li>Choisissez des questions dont vous seul connaissez les réponses</li>
                            <li>Les réponses doivent être faciles à retenir mais difficiles à deviner</li>
                            <li>Évitez les questions avec des réponses qui peuvent changer</li>
                        </ul>
                    </div>

                    <!-- Boutons -->
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="Views/user/dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Retour
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-2"></i>
                            <?= empty($questions_existantes) ? 'Enregistrer' : 'Mettre à jour' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validation des doublons
        document.getElementById('securityForm').addEventListener('submit', function(e) {
            const questions = [
                document.getElementsByName('question1')[0].value,
                document.getElementsByName('question2')[0].value,
                document.getElementsByName('question3')[0].value
            ];
            
            // Vérifier les doublons
            const uniqueQuestions = new Set(questions);
            if (uniqueQuestions.size < 3) {
                e.preventDefault();
                alert('⚠️ Attention : Les questions doivent être différentes les unes des autres.');
                return false;
            }
            
            // Vérifier les réponses
            const reponses = [
                document.getElementsByName('reponse1')[0].value,
                document.getElementsByName('reponse2')[0].value,
                document.getElementsByName('reponse3')[0].value
            ];
            
            for (let i = 0; i < reponses.length; i++) {
                if (reponses[i].trim().length < 2) {
                    e.preventDefault();
                    alert('❌ Les réponses doivent contenir au moins 2 caractères.');
                    return false;
                }
            }
        });

        // Auto-remplissage des suggestions
        document.querySelectorAll('.suggestion-item').forEach(item => {
            item.addEventListener('click', function() {
                const questionText = this.textContent;
                const questionField = this.closest('.suggestions').previousElementSibling;
                questionField.value = this.getAttribute('onclick').match(/'([^']+)'/)[1];
            });
        });

        // Indicateur visuel pour les champs modifiés
        document.querySelectorAll('input[type="text"]').forEach(input => {
            input.addEventListener('input', function() {
                if (this.value.trim() !== '') {
                    this.style.borderColor = '#4361ee';
                } else {
                    this.style.borderColor = '';
                }
            });
        });
    </script>
</body>
</html>