<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars( $title ?? 'Obitleague', ENT_QUOTES, 'UTF-8' ) ?></title>
<style>
:root{color-scheme:light;--ink:#15251e;--muted:#56645d;--paper:#f4f5ef;--card:#fff;--accent:#b58622;--line:#dce2da;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink)}header,main,footer{width:min(100% - 2rem,65rem);margin:auto}select{font:inherit;padding:.65rem;border:1px solid #aab5ad;border-radius:.4rem;width:100%;background:#fff;color:var(--ink)}header{padding:1rem 0;border-bottom:1px solid var(--line);display:flex;gap:1rem;align-items:center;justify-content:space-between}header a{color:inherit;text-decoration:none;font-weight:700}nav{display:flex;gap:1rem}main{padding:2rem 0}footer{padding:1rem 0 2rem;color:var(--muted);font-size:.9rem}.card{background:var(--card);border:1px solid var(--line);border-radius:.8rem;padding:1.2rem;margin:1rem 0}.people{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem}.person{padding:1rem;border-radius:.7rem;background:var(--card);border:1px solid var(--line)}.person small{display:block;color:var(--muted)}label{display:block;margin:.8rem 0 .2rem}input,button{font:inherit;padding:.65rem;border:1px solid #aab5ad;border-radius:.4rem}input[type=text],input[type=password],input[type=search]{width:100%}button,.button{background:var(--ink);color:#fff;border:0;cursor:pointer;text-decoration:none;display:inline-block;padding:.7rem 1rem;border-radius:.4rem}.row{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}.error{color:#8a1b1b}.success{color:#14633c}.pick{display:flex;gap:.6rem;align-items:center}.pick select{flex:1}table{width:100%;border-collapse:collapse}td,th{padding:.5rem;text-align:left;border-bottom:1px solid var(--line)}@media(max-width:500px){header{align-items:flex-start;flex-direction:column}nav{flex-wrap:wrap}}
</style>
</head>
<body>
<header><a href="/">Obitleague</a><nav><a href="/people">People</a><a href="/standings">Standings</a><?php if ( empty( $account ) ) : ?><a href="/login">Sign in</a><a href="/register">Register</a><?php else : ?><span><?= htmlspecialchars( $account['display_name'], ENT_QUOTES, 'UTF-8' ) ?></span><a href="/team">My team</a><?php if ( ! empty( $account['is_operator'] ) ) : ?><a href="/review">Review</a><?php endif; ?><?php endif; ?></nav></header>
<main>
<?php require __DIR__ . '/' . $view; ?>
</main>
<footer>Seasonal game · Verified deaths only · <?= (int) date( 'Y' ) ?> Obitleague</footer>
</body>
</html>
