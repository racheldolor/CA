<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Application Status - Culture and Arts - BatStateU TNEU</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 2rem 1rem;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
        }

        .status-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .header {
            background: #ff5a5a;
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            opacity: 0.9;
        }

        .form-section {
            padding: 2rem;
        }

        .input-group {
            margin-bottom: 1.5rem;
        }

        .input-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }

        .input-group input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .input-group input:focus {
            outline: none;
            border-color: #ff5a5a;
            box-shadow: 0 0 0 3px rgba(255, 90, 90, 0.1);
        }

        .check-btn {
            width: 100%;
            background: #ff5a5a;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .check-btn:hover {
            background: #ff3333;
            transform: translateY(-2px);
        }

        .check-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .result-section {
            display: none;
            margin-top: 2rem;
            padding: 2rem;
            background: #f8f9fa;
            border-top: 1px solid #eee;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .application-info {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #666;
        }

        .info-value {
            color: #333;
            text-align: right;
        }

        .status-message {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #ff5a5a;
        }

        .status-message h3 {
            margin-bottom: 1rem;
            color: #333;
        }

        .status-message p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 0.5rem;
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
            margin-top: 1rem;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .back-link {
            text-align: center;
            margin-top: 2rem;
        }

        .back-link a {
            color: white;
            text-decoration: none;
            display: inline-block;
            padding: 0.75rem 1.25rem;
            background: #ff5a5a;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .back-link a:hover {
            background: #ff3333;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                padding: 1.5rem;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
            
            .form-section {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="status-card">
            <div class="header">
                <h1>Check Application Status</h1>
                <p>Enter your SR Code to check the status of your performer profile application</p>
            </div>
            
            <div class="form-section">
                <form id="statusForm">
                    <div class="input-group">
                        <label for="srCode">SR Code</label>
                        <input type="text" id="srCode" name="sr_code" placeholder="Enter your SR Code (e.g., 21-12345)" required>
                    </div>
                    
                    <button type="submit" class="check-btn" id="checkBtn">
                        Check Status
                    </button>
                </form>
                
                <div id="resultSection" class="result-section">
                    <!-- Results will be displayed here -->
                </div>
            </div>
        </div>
        
        <div class="back-link">
            <a href="../index.php">← Back to Home</a>
        </div>
    </div>

    <script>
        document.getElementById('statusForm').addEventListener('submit', function(e) {
            e.preventDefault();
            checkApplicationStatus();
        });

        async function checkApplicationStatus() {
            const srCode = document.getElementById('srCode').value.trim();
            const checkBtn = document.getElementById('checkBtn');
            const resultSection = document.getElementById('resultSection');
            
            if (!srCode) {
                alert('Please enter your SR Code');
                return;
            }
            
            // Show loading state
            checkBtn.disabled = true;
            checkBtn.textContent = 'Checking...';
            resultSection.style.display = 'block';
            resultSection.innerHTML = '<div class="loading">Checking your application status...</div>';
            
            try {
                const response = await fetch('../head-staff/check_application_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        sr_code: srCode
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displayApplicationStatus(data.application);
                } else {
                    displayError(data.message);
                }
                
            } catch (error) {
                displayError('Error checking application status. Please try again.');
                console.error('Error:', error);
            } finally {
                checkBtn.disabled = false;
                checkBtn.textContent = 'Check Status';
            }
        }

        function displayApplicationStatus(application) {
            const resultSection = document.getElementById('resultSection');
            
            const html = `
                <div class="status-badge" style="background-color: ${application.status_color}">
                    ${application.status.toUpperCase().replace('_', ' ')}
                </div>
                
                <div class="application-info">
                    <div class="info-row">
                        <span class="info-label">Applicant Name:</span>
                        <span class="info-value">${application.full_name}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">SR Code:</span>
                        <span class="info-value">${application.sr_code}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Campus:</span>
                        <span class="info-value">${application.campus}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Program:</span>
                        <span class="info-value">${application.program}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Performance Type:</span>
                        <span class="info-value">${application.performance_type}</span>
                    </div>
                    ${application.reviewed_by ? `
                    <div class="info-row">
                        <span class="info-label">Reviewed By:</span>
                        <span class="info-value">${application.reviewed_by}</span>
                    </div>
                    ` : ''}
                </div>
                
                <div class="status-message">
                    <h3>${application.status_message}</h3>
                    <p>${application.next_steps}</p>
                </div>
            `;
            
            resultSection.innerHTML = html;
        }

        function displayError(message) {
            const resultSection = document.getElementById('resultSection');
            resultSection.innerHTML = `
                <div class="error-message">
                    <strong>Error:</strong> ${message}
                </div>
            `;
        }

        // Auto-focus on input
        document.getElementById('srCode').focus();
    </script>
</body>
</html>