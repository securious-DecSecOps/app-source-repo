<!doctype html>
<html lang="en">
<?php include("inc/head.php"); ?>
<body>

<div class="wrapper">
<?php include("inc/menu.php"); ?>
        <div class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="header">
                                <h4 class="title"><?php echo(MENU_SETTINGS); ?></h4>
                            </div>
                            <div class="content table-responsive table-full-width">
                                <table class="table table-hover table-striped">
                                    <tbody id="settings-settings-body">
                                    </tbody>
                                </table>
                                <button id="settings-dbreset" class="btn btn-danger btn-fill btn-block"><?php echo(RESETDB); ?></button>
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
