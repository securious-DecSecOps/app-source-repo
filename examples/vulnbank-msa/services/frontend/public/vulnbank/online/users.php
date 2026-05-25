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
                            <button id="users-createuser" class="btn btn-info btn-fill"><?php echo(CREATE_USER); ?></button>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="header">
                                <h4 class="title">Users</h4>
                            </div>
                            <div class="content table-responsive table-full-width">
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <th style="text-align:center;vertical-align:middle">ID</th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(USER); ?></th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(FIRSTNAME); ?></th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(LASTNAME); ?></th>
                                        <th style="text-align:center;vertical-align:middle">E-mail</th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(ACCOUNT); ?></th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(CREDITCARD); ?></th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(BIRTHDATE); ?></th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(BALANCE); ?></th>
                                        <th style="text-align:center;vertical-align:middle"><?php echo(ROLE); ?></th>
                                        <th style="text-align:center;vertical-align:middle">OTP</th>
                                        </th>
                                    </thead>
                                    <tbody id="users-users-body">
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
