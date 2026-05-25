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
                            <button id="history-removefailed" class="btn btn-danger btn-fill"><?php echo(HISTORY_FAILED); ?></button>
                            <button id="history-canceloperations" class="btn btn-warning btn-fill"><?php echo(HISTORY_CANCEL); ?></button>
                        </div>
                    </div>
                </div>

                <div class="row" id="history-searchdiv">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="header">
                                <h4 class="title"><?php echo(MENU_HISTORY); ?></h4>
                                <p id="history-searchinfo" class="category"/>
                            </div>
                            <div class="content table-responsive table-full-width">
                                <table id="history-history" class="table table-hover table-striped">
                                    <thead>
                                        <th><?php echo(STATE); ?></th>
                                        <th><?php echo(AMOUNT); ?></th>
                                        <th><?php echo(USER); ?></th>
                                        <th><?php echo(ACCOUNT); ?></th>
                                        <th><?php echo(CREDITCARD); ?></th>
                                        <th><?php echo(DATE); ?></th>
                                        <th><?php echo(COMMENT); ?></th>
                                    </thead>
                                    <tbody id="history-history-body">
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
