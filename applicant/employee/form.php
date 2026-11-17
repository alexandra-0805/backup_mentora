<?php
header('Location: ../../auth/signup.php?pathway=employee_va');
exit;
/*
require_once __DIR__ . '/../../config/db.php';
$errors=[];$saved=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $first=trim((string)($_POST['first_name']??''));$last=trim((string)($_POST['last_name']??''));$email=trim((string)($_POST['email']??''));
    if($first===''||$last==='')$errors[]='Please enter your name.';
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))$errors[]='Please enter a valid email address.';
    if(!$errors){$check=$pdo->prepare('SELECT id FROM users WHERE email=?');$check->execute([$email]);if($check->fetch())$errors[]='An account already exists for that email.';}
    if(!$errors){$insert=$pdo->prepare('INSERT INTO users(first_name,last_name,email,pathway,password_hash) VALUES(?,?,?,?,?)');$insert->execute([$first,$last,$email,'employee_va',password_hash(bin2hex(random_bytes(8)),PASSWORD_DEFAULT)]);$saved=true;}
}
$pathway='employee';$pageTitle='Apply as an employee';$activePage='';require __DIR__.'/../../includes/applicant_layout.php';
?>
<div class="admin-heading"><div><h1>Apply as an employee</h1><p>Tell us about yourself, your strengths and the kind of work you want to do.</p></div><span class="badge info">New application</span></div><div class="applicant-banner"><strong>Professional and non-professional applicants are welcome.</strong>Fields marked with * are required.</div><?php if($saved): ?><div class="notice success">Application received. You can sign in to follow your progress.</div><?php elseif($errors): ?><div class="alert"><?= htmlspecialchars(implode(' ',$errors)) ?></div><?php endif; ?><form class="pathway-card applicant-form" method="post"><h3>Personal & contact details</h3><div class="form-grid"><div class="field"><label>First name *</label><input name="first_name" required></div><div class="field"><label>Last name *</label><input name="last_name" required></div><div class="field"><label>Email address *</label><input type="email" name="email" required></div><div class="field"><label>Mobile number</label><input name="mobile"></div><div class="field full"><label>City / province</label><input name="location" placeholder="Enter your current location"></div><div class="field full"><label>Skills & background</label><textarea rows="4" name="skills" placeholder="Tell us about your skills, tools, education or work experience."></textarea></div><div class="field"><label>Desired role *</label><input name="desired_role" placeholder="Select the role you want to apply for"></div><div class="field"><label>Earliest start date</label><input type="date" name="start_date"></div></div><label><input type="checkbox" required> I confirm the information provided is accurate.</label><div class="form-actions"><button class="admin-btn primary" type="submit">Submit employee application</button></div></form><?php require __DIR__.'/../../includes/applicant_footer.php'; */