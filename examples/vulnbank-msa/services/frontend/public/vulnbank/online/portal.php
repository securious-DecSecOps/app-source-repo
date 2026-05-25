<!doctype html>
<html lang="en">
<?php include("inc/head.php"); ?>
<body>

<div class="wrapper">
<?php include("inc/menu.php"); ?>
        <div class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="header">
                                <h4 class="title"><?php echo(MENU_STATISTICS); ?></h4>
                                <p class="category"><?php echo(PORTAL_DURATION); ?></p>
                            </div>
                            <div class="content">
                                <div id="chartHours" class="ct-chart"></div>
                                <div class="footer">
                                    <div class="legend">
                                        <i class="fa fa-circle text-info"></i> <?php echo(INCOME); ?>
                                        <i class="fa fa-circle text-danger"></i> <?php echo(OUTCOME); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="header">
                                <h4 class="title"><?php echo(PORTAL_TRANSACTIONS); ?></h4>
                                <p class="category"><?php echo(PORTAL_TRANSACTIONS_DURATION); ?></p>
                            </div>
                            <div class="content table-responsive table-full-width">
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <th><?php echo(STATE); ?></th>
                                        <th><?php echo(AMOUNT); ?></th>
                                        <th><?php echo(USER); ?></th>
                                        <th><?php echo(ACCOUNT); ?></th>
                                        <th><?php echo(CREDITCARD); ?></th>
                                        <th><?php echo(DATE); ?></th>
                                        <th><?php echo(COMMENT); ?></th>
                                    </thead>
                                    <tbody id="portal-recent-transactions">
                                    </tbody>
                                </table>
                            </div>
                        </div>

                </div>
            </div>
        </div>


<?php include("inc/footer.php"); ?>
    </div>
</div>


</body>
</html>
