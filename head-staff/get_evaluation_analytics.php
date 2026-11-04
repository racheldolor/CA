<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['head', 'admin', 'director'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// RBAC: Determine access level
$user_role = $_SESSION['user_role'];
$user_email = $_SESSION['user_email'];
$user_campus = $_SESSION['user_campus'] ?? null;

$canViewAll = ($user_role === 'admin' || ($user_campus === 'Pablo Borbon' && $user_role === 'head'));

try {
    $pdo = getDBConnection();
    
    $mode = $_GET['mode'] ?? 'all';
    $eventId = $_GET['event_id'] ?? null;
    
    // Base query for evaluations
    $evaluationQuery = "SELECT e.*, ev.title as event_title, ev.start_date, ev.end_date, 
                       sa.first_name, sa.last_name, sa.sr_code
                       FROM event_evaluations e
                       JOIN events ev ON e.event_id = ev.id
                       JOIN student_artists sa ON e.student_id = sa.id";
    
    $params = [];
    $whereConditions = [];
    
    if (($mode === 'specific' || $mode === 'individual') && $eventId) {
        $whereConditions[] = "e.event_id = ?";
        $params[] = $eventId;
    }
    
    if (!empty($whereConditions)) {
        $evaluationQuery .= " WHERE " . implode(' AND ', $whereConditions);
    }
    
    $evaluationQuery .= " ORDER BY e.submitted_at DESC";
    
    $stmt = $pdo->prepare($evaluationQuery);
    $stmt->execute($params);
    $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all available events (with or without evaluations) with campus filtering
    $eventsQuery = "SELECT ev.id, ev.title, ev.location, ev.category, 
                    ev.start_date as event_date, ev.start_date,
                    TO_CHAR(ev.start_date, 'FMMonth DD, YYYY') as formatted_date,
                    COUNT(e.id) as evaluation_count
                    FROM events ev
                    LEFT JOIN event_evaluations e ON ev.id = e.event_id";
    
    $eventParams = [];
    if (!$canViewAll && $user_campus) {
        $eventsQuery .= " WHERE ev.campus = ?";
        $eventParams[] = $user_campus;
    }
    
    $eventsQuery .= " GROUP BY ev.id, ev.title, ev.location, ev.category, ev.start_date
                    ORDER BY ev.start_date DESC";
    $eventsStmt = $pdo->prepare($eventsQuery);
    $eventsStmt->execute($eventParams);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $statistics = calculateStatistics($evaluations, $pdo, $eventId);
    
    // Get rating distribution
    $ratingDistribution = getRatingDistribution($evaluations);
    
    // Get question scores
    $questionScores = getQuestionScores($evaluations);
    
    // Get trends data
    $trends = getTrendsData($evaluations);
    
    // Generate insights
    $insights = generateInsights($evaluations, $statistics);
    
    // Get comments analysis
    $comments = getCommentsAnalysis($evaluations);
    
    echo json_encode([
        'success' => true,
        'evaluations' => $evaluations,
        'events' => $events,
        'statistics' => $statistics,
        'rating_distribution' => $ratingDistribution,
        'question_scores' => $questionScores,
        'trends' => $trends,
        'insights' => $insights,
        'comments' => $comments
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error loading evaluation analytics: ' . $e->getMessage()]);
}

function calculateStatistics(array $evaluations, \PDO $pdo, ?int $eventId = null): array {
    $totalEvaluations = count($evaluations);
    
    if ($totalEvaluations === 0) {
        return [
            'total_evaluations' => 0,
            'average_rating' => 0.0,
            'response_rate' => 0,
            'satisfaction_score' => 0
        ];
    }
    
    // Calculate average rating across all questions
    $totalRatings = 0;
    $ratingCount = 0;
    $satisfiedCount = 0;
    
    foreach ($evaluations as $evaluation) {
        $ratings = [
            $evaluation['q1_rating'], $evaluation['q2_rating'], $evaluation['q3_rating'],
            $evaluation['q4_rating'], $evaluation['q5_rating'], $evaluation['q6_rating'],
            $evaluation['q7_rating'], $evaluation['q8_rating'], $evaluation['q9_rating'],
            $evaluation['q10_rating'], $evaluation['q11_rating'], $evaluation['q12_rating']
        ];
        
        foreach ($ratings as $rating) {
            if ($rating !== null) {
                $totalRatings += $rating;
                $ratingCount++;
                
                // Consider 4 and 5 as satisfied
                if ($rating >= 4) {
                    $satisfiedCount++;
                }
            }
        }
    }
    
    $averageRating = $ratingCount > 0 ? $totalRatings / $ratingCount : 0;
    $satisfactionScore = $ratingCount > 0 ? round(($satisfiedCount / $ratingCount) * 100) : 0;
    
    // Calculate response rate
    $responseRate = 0;
    if ($eventId) {
        // Get total participants for specific event
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_participants FROM event_participants WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $totalParticipants = $stmt->fetchColumn();
        $responseRate = $totalParticipants > 0 ? round(($totalEvaluations / $totalParticipants) * 100) : 0;
    } else {
        // For all events, calculate average response rate
        $eventIds = array_unique(array_column($evaluations, 'event_id'));
        $totalResponseRates = 0;
        $eventCount = 0;
        
        foreach ($eventIds as $eId) {
            $eventEvaluations = array_filter($evaluations, function($e) use ($eId) {
                return $e['event_id'] == $eId;
            });
            $evalCount = count($eventEvaluations);
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as total_participants FROM event_participants WHERE event_id = ?");
            $stmt->execute([$eId]);
            $totalParticipants = $stmt->fetchColumn();
            
            if ($totalParticipants > 0) {
                $totalResponseRates += ($evalCount / $totalParticipants) * 100;
                $eventCount++;
            }
        }
        
        $responseRate = $eventCount > 0 ? round($totalResponseRates / $eventCount) : 0;
    }
    
    return [
        'total_evaluations' => $totalEvaluations,
        'average_rating' => $averageRating,
        'response_rate' => $responseRate,
        'satisfaction_score' => $satisfactionScore
    ];
}

function getRatingDistribution(array $evaluations): array {
    $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    
    foreach ($evaluations as $evaluation) {
        $ratings = [
            $evaluation['q1_rating'], $evaluation['q2_rating'], $evaluation['q3_rating'],
            $evaluation['q4_rating'], $evaluation['q5_rating'], $evaluation['q6_rating'],
            $evaluation['q7_rating'], $evaluation['q8_rating'], $evaluation['q9_rating'],
            $evaluation['q10_rating'], $evaluation['q11_rating'], $evaluation['q12_rating']
        ];
        
        foreach ($ratings as $rating) {
            if ($rating !== null && isset($distribution[$rating])) {
                $distribution[$rating]++;
            }
        }
    }
    
    $result = [];
    foreach ($distribution as $rating => $count) {
        $result[] = ['rating' => $rating, 'count' => $count];
    }
    
    return $result;
}

function getQuestionScores(array $evaluations): array {
    $questions = [
        'q1' => 'Overall rating of seminar/training',
        'q2' => 'Appropriateness of time and resources',
        'q3' => 'Objectives and expectations achieved',
        'q4' => 'Session activities relevance',
        'q5' => 'Sufficient time for discussion',
        'q6' => 'Materials and tasks usefulness',
        'q7' => 'Trainer knowledge and insights',
        'q8' => 'Trainer explanation quality',
        'q9' => 'Learning environment',
        'q10' => 'Time management',
        'q11' => 'Trainer responsiveness to needs',
        'q12' => 'Venue/platform conduciveness'
    ];
    
    $questionScores = [];
    
    foreach ($questions as $qCode => $qText) {
        $ratingColumn = $qCode . '_rating';
        $ratings = array_column($evaluations, $ratingColumn);
        $ratings = array_filter($ratings, function($r) { return $r !== null; });
        
        if (count($ratings) > 0) {
            $averageScore = array_sum($ratings) / count($ratings);
            $questionScores[] = [
                'question' => $qText,
                'average_score' => $averageScore,
                'response_count' => count($ratings)
            ];
        }
    }
    
    // Sort by average score descending
    usort($questionScores, function($a, $b) {
        return $b['average_score'] <=> $a['average_score'];
    });
    
    return $questionScores;
}

function getTrendsData(array $evaluations): array {
    if (empty($evaluations)) {
        return [];
    }
    
    // Group evaluations by date
    $dateGroups = [];
    foreach ($evaluations as $evaluation) {
        $date = date('Y-m-d', strtotime($evaluation['start_date']));
        if (!isset($dateGroups[$date])) {
            $dateGroups[$date] = [];
        }
        $dateGroups[$date][] = $evaluation;
    }
    
    $trends = [];
    foreach ($dateGroups as $date => $dayEvaluations) {
        $totalRatings = 0;
        $ratingCount = 0;
        
        foreach ($dayEvaluations as $evaluation) {
            $ratings = [
                $evaluation['q1_rating'], $evaluation['q2_rating'], $evaluation['q3_rating'],
                $evaluation['q4_rating'], $evaluation['q5_rating'], $evaluation['q6_rating'],
                $evaluation['q7_rating'], $evaluation['q8_rating'], $evaluation['q9_rating'],
                $evaluation['q10_rating'], $evaluation['q11_rating'], $evaluation['q12_rating']
            ];
            
            foreach ($ratings as $rating) {
                if ($rating !== null) {
                    $totalRatings += $rating;
                    $ratingCount++;
                }
            }
        }
        
        if ($ratingCount > 0) {
            $averageRating = $totalRatings / $ratingCount;
            $trends[] = [
                'date' => date('M j', strtotime($date)),
                'full_date' => $date,
                'average_rating' => $averageRating,
                'evaluation_count' => count($dayEvaluations)
            ];
        }
    }
    
    // Sort by date
    usort($trends, function($a, $b) {
        return strtotime($a['full_date']) <=> strtotime($b['full_date']);
    });
    
    return $trends;
}

function generateInsights(array $evaluations, array $statistics): ?array {
    // Return null if no evaluations exist
    if (empty($evaluations) || $statistics['total_evaluations'] === 0) {
        return null;
    }
    
    $insights = [];
    
    // Overall performance insight
    $avgRating = $statistics['average_rating'];
    if ($avgRating >= 4.5) {
        $insights['overall'] = "Excellent performance! Events are consistently rated very highly with an average rating of " . number_format($avgRating, 1) . "/5.0. This indicates exceptional satisfaction among participants.";
    } elseif ($avgRating >= 4.0) {
        $insights['overall'] = "Strong performance with an average rating of " . number_format($avgRating, 1) . "/5.0. Events are well-received by participants with good satisfaction levels.";
    } elseif ($avgRating >= 3.5) {
        $insights['overall'] = "Moderate performance with an average rating of " . number_format($avgRating, 1) . "/5.0. There's room for improvement to enhance participant satisfaction.";
    } elseif ($avgRating >= 3.0) {
        $insights['overall'] = "Below average performance with a rating of " . number_format($avgRating, 1) . "/5.0. Significant improvements are needed to meet participant expectations.";
    } else {
        $insights['overall'] = "Performance needs urgent attention with a low average rating of " . number_format($avgRating, 1) . "/5.0. Major improvements are required across all aspects.";
    }
    
    // Response rate insight
    $responseRate = $statistics['response_rate'];
    if ($responseRate >= 70) {
        $insights['strengths'] = "Excellent response rate of {$responseRate}% indicates high participant engagement and willingness to provide feedback.";
    } elseif ($responseRate >= 50) {
        $insights['strengths'] = "Good response rate of {$responseRate}% shows solid participant engagement in the feedback process.";
    } else {
        $insights['improvements'] = "Low response rate of {$responseRate}% suggests need to improve feedback collection methods and participant engagement strategies.";
    }
    
    // Satisfaction score insight
    $satisfactionScore = $statistics['satisfaction_score'];
    if ($satisfactionScore >= 80) {
        if (!isset($insights['strengths'])) {
            $insights['strengths'] = "High satisfaction score of {$satisfactionScore}% indicates most participants are very pleased with the events.";
        } else {
            $insights['strengths'] .= " Additionally, the high satisfaction score of {$satisfactionScore}% shows excellent participant contentment.";
        }
    } elseif ($satisfactionScore >= 60) {
        $insights['trends'] = "Moderate satisfaction score of {$satisfactionScore}% suggests room for improvement in event quality and participant experience.";
    } else {
        if (!isset($insights['improvements'])) {
            $insights['improvements'] = "Low satisfaction score of {$satisfactionScore}% indicates significant improvements needed in event planning and execution.";
        } else {
            $insights['improvements'] .= " The low satisfaction score of {$satisfactionScore}% also highlights areas needing attention.";
        }
    }
    
    // Recommendations based on overall performance
    if ($avgRating < 4.0) {
        $insights['recommendations'] = "Focus on improving event organization, venue quality, and staff support. Consider gathering more detailed feedback to identify specific areas for enhancement.";
    } elseif ($responseRate < 50) {
        $insights['recommendations'] = "Implement strategies to increase feedback participation, such as incentives, simplified evaluation forms, or immediate post-event surveys.";
    } else {
        $insights['recommendations'] = "Continue maintaining high standards while exploring innovative ways to further enhance participant experience and engagement.";
    }
    
    return $insights;
}

function getCommentsAnalysis(array $evaluations): array {
    $positiveComments = [];
    $improvementComments = [];
    $allComments = [];
    
    foreach ($evaluations as $evaluation) {
        $avgRating = calculateCommentRating($evaluation);
        
        // Areas for Improvement: Specifically from Comments (Q15)
        if (!empty($evaluation['q15_comments'])) {
            $commentText = trim($evaluation['q15_comments']);
            
            // Filter out non-meaningful responses
            $skipKeywords = ['none', 'n/a', 'na', 'nothing', 'no comment', 'good', 'ok', 'okay'];
            $isSkippable = false;
            foreach ($skipKeywords as $skip) {
                if (strtolower($commentText) === $skip || strlen($commentText) <= 3) {
                    $isSkippable = true;
                    break;
                }
            }
            
            if (!$isSkippable) {
                $comment = [
                    'type' => 'Comments',
                    'text' => $commentText,
                    'event_title' => $evaluation['event_title'],
                    'date' => date('M j, Y', strtotime($evaluation['start_date'])),
                    'rating_context' => $avgRating
                ];
                
                $improvementComments[] = $comment;
                $allComments[] = $comment;
            }
        }
        
        // Positive Comments: From Opinion (Q13) and Suggestions (Q14)  
        if (!empty($evaluation['q13_opinion'])) {
            $opinionText = trim($evaluation['q13_opinion']);
            
            // Filter out non-meaningful responses
            $skipKeywords = ['none', 'n/a', 'na', 'nothing', 'no opinion', 'no comment'];
            $isSkippable = false;
            foreach ($skipKeywords as $skip) {
                if (strtolower($opinionText) === $skip || strlen($opinionText) <= 3) {
                    $isSkippable = true;
                    break;
                }
            }
            
            if (!$isSkippable && $avgRating >= 3.5) {
                $comment = [
                    'type' => 'Opinion',
                    'text' => $opinionText,
                    'event_title' => $evaluation['event_title'],
                    'date' => date('M j, Y', strtotime($evaluation['start_date'])),
                    'rating_context' => $avgRating
                ];
                
                $positiveComments[] = $comment;
                $allComments[] = $comment;
            }
        }
        
        if (!empty($evaluation['q14_suggestions'])) {
            $suggestionText = trim($evaluation['q14_suggestions']);
            
            // Filter out non-meaningful responses
            $skipKeywords = ['none', 'n/a', 'na', 'nothing', 'no suggestions', 'no comment'];
            $isSkippable = false;
            foreach ($skipKeywords as $skip) {
                if (strtolower($suggestionText) === $skip || strlen($suggestionText) <= 3) {
                    $isSkippable = true;
                    break;
                }
            }
            
            if (!$isSkippable && $avgRating >= 3.5) {
                $comment = [
                    'type' => 'Suggestions',
                    'text' => $suggestionText,
                    'event_title' => $evaluation['event_title'],
                    'date' => date('M j, Y', strtotime($evaluation['start_date'])),
                    'rating_context' => $avgRating
                ];
                
                $positiveComments[] = $comment;
                $allComments[] = $comment;
            }
        }
    }
    
    // Limit to most recent 10 comments each
    $positiveComments = array_slice($positiveComments, 0, 10);
    $improvementComments = array_slice($improvementComments, 0, 10);
    $allComments = array_slice($allComments, 0, 20);
    
    return [
        'positive' => $positiveComments,
        'improvement' => $improvementComments,
        'all' => $allComments
    ];
}

function calculateCommentRating(array $evaluation): float {
    $ratings = [
        $evaluation['q1_rating'], $evaluation['q2_rating'], $evaluation['q3_rating'],
        $evaluation['q4_rating'], $evaluation['q5_rating'], $evaluation['q6_rating'],
        $evaluation['q7_rating'], $evaluation['q8_rating'], $evaluation['q9_rating'],
        $evaluation['q10_rating'], $evaluation['q11_rating'], $evaluation['q12_rating']
    ];
    
    $validRatings = array_filter($ratings, function($r) { return $r !== null; });
    return count($validRatings) > 0 ? array_sum($validRatings) / count($validRatings) : 0;
}
?>