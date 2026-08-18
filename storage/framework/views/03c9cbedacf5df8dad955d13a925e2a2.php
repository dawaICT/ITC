<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WUCPortal</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo e(asset('css/app.css')); ?>">
    <style>
        body {
            background: linear-gradient(135deg, #1f1f1f, #4a2b9c);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .wuc-login-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 440px;
            padding: 2.5rem;
        }
    </style>
</head>
<body>
    <div class="wuc-login-card">
        <div class="text-center mb-4">
            <div class="stat-icon purple mx-auto mb-3" style="width: 60px; height: 60px; font-size: 2rem; border-radius: 16px;">
                <i class="fas fa-graduation-cap text-white"></i>
            </div>
            <h3 class="font-weight-bold">WUCPortal</h3>
            <p class="text-muted small m-0">Waterfalls University College Portal Login</p>
        </div>

        <?php if($errors->any()): ?>
            <div class="alert alert-danger">
                <ul class="m-0 ps-3 small">
                    <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(route('login.post')); ?>">
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label font-weight-medium">Email or Student / Staff ID</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" name="email" class="form-control" value="<?php echo e(old('email')); ?>" required autofocus placeholder="Enter ID or Email">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label font-weight-medium">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" name="password" class="form-control" required placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 font-weight-bold" style="background-color: var(--brand-primary); border: none;">
                <i class="fas fa-right-to-bracket me-2"></i> Sign In to Portal
            </button>
        </form>
    </div>
</body>
</html>
<?php /**PATH C:\xampp\htdocs\wucportal\resources\views/auth/login.blade.php ENDPATH**/ ?>