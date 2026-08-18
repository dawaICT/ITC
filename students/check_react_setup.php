<?php
require_once __DIR__ . '/includes/guard.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>React Setup Checker</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- React Development Dependencies -->
    <script src="https://unpkg.com/react@18/umd/react.development.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js" crossorigin></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
</head>
<body>
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <main class="content-wrapper pt-3 pb-5">
    <div class="container mt-4">
        <h1>React Setup Checker</h1>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Environment Check</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush" id="environment-list">
                            <li class="list-group-item">Checking environment...</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">File Check</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush" id="file-list">
                            <li class="list-group-item">Checking files...</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">React Test</h5>
                    </div>
                    <div class="card-body">
                        <div id="react-test-container"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Troubleshooting</h5>
                    </div>
                    <div class="card-body">
                        <h6>PowerShell Execution Policy</h6>
                        <p>If you're seeing the error about scripts being disabled, try running Command Prompt instead of PowerShell:</p>
                        <pre class="bg-light p-3 rounded">
1. Right-click on Start menu and select "Command Prompt"
2. cd C:\xampp\htdocs\wucportal
3. npm install
4. npm run build
                        </pre>
                        
                        <h6>Missing node_modules</h6>
                        <p>If you don't have a node_modules folder, you need to install dependencies:</p>
                        <pre class="bg-light p-3 rounded">
1. Double-click setup-react-cmd.bat in the root folder
2. Or run: npm install
                        </pre>
                        
                        <h6>Missing dist folder</h6>
                        <p>If you don't have a compiled bundle, build it:</p>
                        <pre class="bg-light p-3 rounded">
npm run build
                        </pre>
                        
                        <h6>Can't execute npm</h6>
                        <p>Make sure you have Node.js installed:</p>
                        <pre class="bg-light p-3 rounded">
1. Download Node.js from https://nodejs.org/
2. Run the installer
3. Restart your computer
                        </pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script type="text/babel">
        // Simple React test component
        const ReactTestComponent = () => {
            const [count, setCount] = React.useState(0);
            
            return (
                <div className="text-center">
                    <div className="alert alert-success">
                        <strong>Success!</strong> React is working properly.
                    </div>
                    
                    <div className="mb-3">
                        <p>Counter: {count}</p>
                        <button 
                            className="btn btn-primary me-2" 
                            onClick={() => setCount(count + 1)}
                        >
                            Increment
                        </button>
                        <button 
                            className="btn btn-secondary" 
                            onClick={() => setCount(0)}
                        >
                            Reset
                        </button>
                    </div>
                    
                    <p className="text-success">
                        If you can see this message and the buttons work, React is set up correctly!
                    </p>
                </div>
            );
        };
        
        // Render the test component
        const container = document.getElementById('react-test-container');
        ReactDOM.createRoot(container).render(<ReactTestComponent />);
        
        // Check environment
        const envList = document.getElementById('environment-list');
        envList.innerHTML = '';
        
        // Check React
        const addEnvItem = (name, status, details = '') => {
            const item = document.createElement('li');
            item.className = `list-group-item d-flex justify-content-between align-items-center`;
            
            const nameSpan = document.createElement('span');
            nameSpan.textContent = name;
            
            const statusBadge = document.createElement('span');
            statusBadge.className = `badge ${status ? 'bg-success' : 'bg-danger'} rounded-pill`;
            statusBadge.textContent = status ? 'OK' : 'Missing';
            
            item.appendChild(nameSpan);
            
            if (details) {
                const detailsSmall = document.createElement('small');
                detailsSmall.className = 'text-muted';
                detailsSmall.textContent = details;
                item.appendChild(detailsSmall);
            }
            
            item.appendChild(statusBadge);
            envList.appendChild(item);
        };
        
        // Check environment
        addEnvItem('React', typeof React !== 'undefined', React ? `v${React.version}` : '');
        addEnvItem('ReactDOM', typeof ReactDOM !== 'undefined', ReactDOM ? `v${ReactDOM.version}` : '');
        addEnvItem('Babel', typeof Babel !== 'undefined');
        
        // Check if Node.js is installed (we can't actually check this from the browser)
        const nodeInstalled = false; // We'll just show this as unknown
        addEnvItem('Node.js', null, 'Cannot check from browser');
        
        // Check if npm is installed (we can't actually check this from the browser)
        addEnvItem('npm', null, 'Cannot check from browser');
        
        // Check files
        const fileList = document.getElementById('file-list');
        fileList.innerHTML = '';
        
        const checkFile = (path, name) => {
            const item = document.createElement('li');
            item.className = 'list-group-item d-flex justify-content-between align-items-center';
            
            const nameSpan = document.createElement('span');
            nameSpan.textContent = name;
            
            const statusSpinner = document.createElement('div');
            statusSpinner.className = 'spinner-border spinner-border-sm text-primary';
            statusSpinner.setAttribute('role', 'status');
            
            item.appendChild(nameSpan);
            item.appendChild(statusSpinner);
            fileList.appendChild(item);
            
            fetch(path, { method: 'HEAD' })
                .then(response => {
                    item.removeChild(statusSpinner);
                    
                    const statusBadge = document.createElement('span');
                    if (response.ok) {
                        statusBadge.className = 'badge bg-success rounded-pill';
                        statusBadge.textContent = 'Found';
                    } else {
                        statusBadge.className = 'badge bg-danger rounded-pill';
                        statusBadge.textContent = 'Not Found';
                    }
                    
                    item.appendChild(statusBadge);
                })
                .catch(error => {
                    item.removeChild(statusSpinner);
                    
                    const statusBadge = document.createElement('span');
                    statusBadge.className = 'badge bg-danger rounded-pill';
                    statusBadge.textContent = 'Error';
                    
                    item.appendChild(statusBadge);
                });
        };
        
        // Check important files
        checkFile('../package.json', 'package.json');
        checkFile('../webpack.config.js', 'webpack.config.js');
        checkFile('../.babelrc', '.babelrc');
        checkFile('dist/js/courseRegistration.bundle.js', 'Bundle JS');
        checkFile('js/react/courseRegistration.js', 'Entry JS');
        checkFile('js/react/components/CourseRegistrationApp.jsx', 'App Component');
    </script>
</body>
</html>
