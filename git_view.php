<?php
// git_view.php
// A simple Git visualization tool for the 4erpv2 project.

// Try to include bootstrap if available to get session context, but don't fail if missing
$bootstrap = __DIR__ . '/config/bootstrap.php';
$is_authenticated = false;
$user_name = 'Guest';

if (file_exists($bootstrap)) {
    require_once $bootstrap;
    // Check if Auth class is available (loaded by bootstrap)
    if (class_exists('Auth')) {
        $auth = new Auth();
        if ($auth->isAuthenticated()) {
            $is_authenticated = true;
            $user = $auth->getCurrentUser();
            $user_name = $user['username'] ?? 'User';
        }
    }
}

// Set generic page headers
header('Content-Type: text/html; charset=utf-8');

// DEBUG: Force display errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to get git log
function getGitLog() {
    // Ensure we run git from the script's directory
    $dir = __DIR__;
    $command = "cd " . escapeshellarg($dir) . " && git log --all --pretty=format:'%h|%p|%s|%an|%at|%D'";
    $output = [];
    $return_var = 0;
    
    // Execute git command
    exec($command, $output, $return_var);
    
    if ($return_var !== 0) {
        // Try fallback if 'git' is not in path or other issue
        return ['error' => 'Failed to execute git log. Exit code: ' . $return_var . '. Output: ' . implode("\n", $output)];
    }
    
    $commits = [];
    foreach ($output as $line) {
        $parts = explode('|', $line);
        if (count($parts) < 5) continue;
        
        $hash = $parts[0];
        $parents = array_filter(explode(' ', $parts[1])); // Filter empty if no parents
        $subject = $parts[2];
        $author = $parts[3];
        $timestamp = $parts[4];
        $refs = isset($parts[5]) ? $parts[5] : '';
        
        $commits[] = [
            'id' => $hash,
            'parents' => array_values($parents), // These are parents
            'label' => substr($subject, 0, 30) . (strlen($subject) > 30 ? '...' : ''),
            'title' => "<strong>$hash</strong><br>$subject<br><em>$author</em><br>" . date('Y-m-d H:i:s', $timestamp),
            'refs' => $refs,
            'group' => strpos($refs, 'HEAD') !== false ? 'HEAD' : (strpos($refs, 'main') !== false ? 'main' : 'other')
        ];
    }
    return $commits;
}

$commits = getGitLog();
$jsonData = json_encode($commits);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Git Visualizer - 4ERP</title>
    <script type="text/javascript" src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 20px;
            color: #1f2937;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: white;
            padding: 15px 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        h1 {
            margin: 0;
            font-size: 1.5rem;
            color: #111827;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .badge-head { background-color: #d1fae5; color: #065f46; }
        .badge-main { background-color: #dbeafe; color: #1e40af; }
        .badge-dev { background-color: #fce7f3; color: #9d174d; }
        
        #mynetwork {
            flex-grow: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }
        
        .controls {
            margin-top: 10px;
            text-align: right;
            font-size: 0.9rem;
            color: #6b7280;
        }
    </style>
</head>
<body>

<header>
    <div>
        <h1>Git Network Graph</h1>
        <small style="color: #6b7280;">Visualizing project history</small>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <?php if (isset($is_authenticated) && $is_authenticated): ?>
            <span style="font-size: 0.9rem; color: #4b5563;">
                Hi, <strong><?php echo htmlspecialchars($user_name); ?></strong>
            </span>
            <a href="index.php" style="text-decoration: none; color: #4b5563; font-size: 0.9rem;">Dashboard</a>
        <?php else: ?>
            <span style="font-size: 0.9rem; color: #4b5563;">Guest Mode</span>
        <?php endif; ?>
        <a href="git_view.php" style="text-decoration: none; background: #2563eb; color: white; padding: 8px 16px; border-radius: 6px; font-weight: 500;">Refresh</a>
    </div>
</header>

<div id="mynetwork"></div>

<div class="controls">
    Scroll to zoom • Drag to pan • Click node for details
</div>

<script type="text/javascript">
    // Data from PHP
    const rawCommits = <?php echo isset($jsonData) ? $jsonData : '[]'; ?>;
    
    // Process data for VisJS
    const nodes = new vis.DataSet();
    const edges = new vis.DataSet();
    
    rawCommits.forEach(commit => {
        // Determine color based on refs
        let color = '#9ca3af'; // default gray
        let shape = 'dot';
        let size = 10;
        let label = '';

        if (commit.refs.length > 0) {
            // Simplify refs display
            let refs = commit.refs.replace('HEAD ->', 'HEAD').split(',');
            label = '\n' + refs.map(r => r.trim()).join('\n');
        }
        
        if (commit.refs.includes('HEAD')) {
            color = '#10b981'; // green for HEAD
            size = 18;
        } else if (commit.refs.includes('main') || commit.refs.includes('master')) {
            color = '#3b82f6'; // blue for main
            size = 14;
        } else if (commit.refs.length > 0) {
            color = '#f59e0b'; // orange for other branches
            size = 14;
        }
        
        // Add Node
        nodes.add({
            id: commit.id,
            label: commit.label + label, 
            title: commit.title, // HTML tooltip
            color: { background: color, border: '#ffffff' },
            size: size,
            shape: shape,
            font: { size: 12, face: 'Inter', multi: 'html', strokeWidth: 2, strokeColor: '#ffffff' }
        });
        
        // Add Edges (Parent -> Child)
        // We want Time to flow Left -> Right. Old -> New.
        // Commits data has ID and PARENTS.
        // Edge: Parent -> Child
        commit.parents.forEach(parentId => {
            edges.add({
                from: parentId,
                to: commit.id,
                arrows: 'to',
                color: { color: '#e5e7eb' }
            });
        });
        // Note: We might miss nodes if the parent isn't in log (e.g. initial commit's parent? No, initial has no parent).
        // Git log --all ensures we get history.
    });

    // Configuration
    const container = document.getElementById('mynetwork');
    const data = {
        nodes: nodes,
        edges: edges
    };
    const options = {
        layout: {
            hierarchical: {
                direction: 'LR', // Left to Right
                sortMethod: 'directed',
                nodeSpacing: 100,
                levelSeparation: 150
            }
        },
        physics: {
            enabled: false,
            hierarchicalRepulsion: {
                nodeDistance: 120
            } 
        },
        interaction: {
            hover: true,
            tooltipDelay: 100
        },
        nodes: {
            borderWidth: 2,
            shadow: true
        },
        edges: {
            width: 2,
            smooth: {
                type: 'cubicBezier',
                forceDirection: 'horizontal',
                roundness: 0.4
            }
        }
    };
    
    const network = new vis.Network(container, data, options);
    
    // Fit to view
    network.once("afterDrawing", function() {
        network.fit();
    });
</script>


</body>
</html>
