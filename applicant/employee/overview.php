<?php
$pathway='employee';$pageTitle='Employee pathway';$activePage='overview.php';require __DIR__.'/../../includes/applicant_layout.php';
require_once __DIR__.'/../../includes/hiring_progress.php';
$progress=mentora_hiring_progress($pdo,$currentUser,'employee');
$stepState=array_column($progress['steps'],'state','label');
$badge=static fn(
    string $state,
    string $done,
    string $current,
    string $upcoming
): string => match($state){'done'=>'<span class="badge">'.$done.'</span>','current'=>'<span class="badge info">'.$current.'</span>',default=>'<span class="badge warn">'.$upcoming.'</span>'};
$stepDetail=array_column($progress['steps'],'detail','label');
?>
<div class="admin-heading">
    <div>
        <h1>Employee pathway</h1>
        <p>Track your hiring progress, upcoming milestones, and next steps in one place.</p>
    </div>
    <span class="badge info">Active pathway</span>
</div>
<div class="applicant-banner">
    <strong>Welcome to your Mentora workspace.</strong>Your application, evaluation, training, and department details are kept together here.</div>
<?php mentora_render_hiring_progress($progress,'Hiring progress'); ?>
<div class="admin-grid"><section class="pathway-card"><h3>Pathway summary</h3><ul class="status-list">
<li><span>Application verified</span><?= $badge($stepState['Application'],'Complete','Form needed','Form needed') ?></li>
<li><span>Evaluation review</span><?= $badge($stepState['Evaluation'],'Complete','In review','Upcoming') ?></li>
<li><span>Interview &amp; offer</span><?= $badge($stepState['Interview & offer'],'Complete','In progress','Upcoming') ?></li>
<li><span>Training</span><?= $badge($stepState['Training'],'Complete',htmlspecialchars($stepDetail['Training']),'Upcoming') ?></li>
<li><span>Department</span><?= $badge($stepState['Department'],htmlspecialchars($stepDetail['Department']),'Awaiting assignment','Upcoming') ?></li>
</ul></section><section class="pathway-card"><h3>Next steps</h3><ul class="checklist">
<?php
$next=[
  'Application'=>['Complete your application form','form.php'],
  'Evaluation'=>['Wait for your evaluation results','evaluation.php'],
  'Interview & offer'=>['Prepare for your interview with HR','evaluation.php'],
  'Training'=>['Complete your employee training','training.php'],
  'Department'=>['Review your assigned department','department.php'],
];
foreach($progress['steps'] as $step):
  if($step['state']==='done')continue;
  [$text,$href]=$next[$step['label']]; ?>
<li class="pending<?= $step['state']==='current'?' is-current':'' ?>"><a href="<?= $href ?>"><?= htmlspecialchars($text) ?></a></li>
<?php endforeach; ?>
<?php if($stepState['Department']==='done'): ?><li class="pending is-current"><a href="reporting.php">Submit your daily work report</a></li><?php endif; ?>
</ul></section></div>
<?php require __DIR__.'/../../includes/applicant_footer.php'; ?>