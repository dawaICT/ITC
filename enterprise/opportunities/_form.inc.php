<?php
declare(strict_types=1);
/** @var array<string,string> $form */
/** @var array<int,array> $categories */
$types = ep_opportunity_types();
?>
<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label" for="title">Title *</label>
        <input class="form-control" id="title" name="title" maxlength="200" required value="<?= ep_h($form['title']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="opportunity_type">Type *</label>
        <select class="form-select" id="opportunity_type" name="opportunity_type" required>
            <?php foreach ($types as $k => $lbl): ?>
                <option value="<?= ep_h($k) ?>"<?= $form['opportunity_type'] === $k ? ' selected' : '' ?>><?= ep_h($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="category_id">Category</label>
        <select class="form-select" id="category_id" name="category_id">
            <option value="">—</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"<?= $form['category_id'] === (string)$cat['id'] ? ' selected' : '' ?>><?= ep_h((string)$cat['category_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="currency">Currency</label>
        <input class="form-control" id="currency" name="currency" value="<?= ep_h($form['currency'] ?: 'ZMW') ?>">
    </div>
    <div class="col-12">
        <label class="form-label" for="short_description">Short description *</label>
        <textarea class="form-control" id="short_description" name="short_description" rows="2" maxlength="500" required><?= ep_h($form['short_description']) ?></textarea>
    </div>
    <div class="col-12">
        <label class="form-label" for="full_description">Full description</label>
        <textarea class="form-control" id="full_description" name="full_description" rows="4"><?= ep_h($form['full_description']) ?></textarea>
    </div>
</div>
<hr class="my-3">
<h3 class="h6">Service / product pricing</h3>
<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label" for="pricing_type">Pricing type</label>
        <select class="form-select" id="pricing_type" name="pricing_type">
            <option value="">—</option>
            <?php foreach (['quote_on_request', 'fixed_price', 'starting_from', 'hourly', 'negotiable'] as $pt): ?>
                <option value="<?= ep_h($pt) ?>"<?= $form['pricing_type'] === $pt ? ' selected' : '' ?>><?= ep_h(str_replace('_', ' ', $pt)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label" for="unit_price">Unit price</label>
        <input class="form-control" type="number" step="0.01" id="unit_price" name="unit_price" value="<?= ep_h($form['unit_price']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="availability_status">Availability</label>
        <select class="form-select" id="availability_status" name="availability_status">
            <?php foreach (['available', 'limited', 'unavailable'] as $av): ?>
                <option value="<?= ep_h($av) ?>"<?= ($form['availability_status'] ?: 'available') === $av ? ' selected' : '' ?>><?= ep_h(ucfirst($av)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="service_area">Service area / coverage</label>
        <input class="form-control" id="service_area" name="service_area" value="<?= ep_h($form['service_area']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="current_capacity">Capacity</label>
        <input class="form-control" type="number" min="0" id="current_capacity" name="current_capacity" value="<?= ep_h($form['current_capacity']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="capacity_period">Capacity period</label>
        <input class="form-control" id="capacity_period" name="capacity_period" placeholder="per month" value="<?= ep_h($form['capacity_period']) ?>">
    </div>
</div>
<hr class="my-3">
<h3 class="h6">Employment profile</h3>
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="preferred_employment_type">Preferred employment type</label>
        <input class="form-control" id="preferred_employment_type" name="preferred_employment_type" value="<?= ep_h($form['preferred_employment_type']) ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label" for="preferred_location">Preferred location</label>
        <input class="form-control" id="preferred_location" name="preferred_location" value="<?= ep_h($form['preferred_location']) ?>">
    </div>
</div>
<hr class="my-3">
<h3 class="h6">Innovation / business idea / investment</h3>
<div class="row g-3">
    <div class="col-12">
        <label class="form-label" for="problem_statement">Problem / market need</label>
        <textarea class="form-control" id="problem_statement" name="problem_statement" rows="2"><?= ep_h($form['problem_statement']) ?></textarea>
    </div>
    <div class="col-12">
        <label class="form-label" for="proposed_solution">Proposed solution</label>
        <textarea class="form-control" id="proposed_solution" name="proposed_solution" rows="2"><?= ep_h($form['proposed_solution']) ?></textarea>
    </div>
    <div class="col-md-4">
        <label class="form-label" for="innovation_stage">Innovation stage</label>
        <input class="form-control" id="innovation_stage" name="innovation_stage" value="<?= ep_h($form['innovation_stage']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="investment_required">Investment required</label>
        <input class="form-control" type="number" step="0.01" id="investment_required" name="investment_required" value="<?= ep_h($form['investment_required']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="investment_purpose">Use of funds</label>
        <input class="form-control" id="investment_purpose" name="investment_purpose" value="<?= ep_h($form['investment_purpose']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="expected_capacity">Expected capacity</label>
        <input class="form-control" type="number" id="expected_capacity" name="expected_capacity" value="<?= ep_h($form['expected_capacity']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="expected_capacity_period">Expected capacity period</label>
        <input class="form-control" id="expected_capacity_period" name="expected_capacity_period" value="<?= ep_h($form['expected_capacity_period']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="employment_potential">Jobs potential</label>
        <input class="form-control" type="number" id="employment_potential" name="employment_potential" value="<?= ep_h($form['employment_potential']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="cost_visibility">Cost visibility</label>
        <select class="form-select" id="cost_visibility" name="cost_visibility">
            <?php foreach (['private', 'summary_public'] as $cv): ?>
                <option value="<?= ep_h($cv) ?>"<?= ($form['cost_visibility'] ?: 'private') === $cv ? ' selected' : '' ?>><?= ep_h(str_replace('_', ' ', $cv)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
