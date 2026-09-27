(function(){
	'use strict';
	var picks=Array.prototype.slice.call(document.querySelectorAll('[data-demo-pick]'));
	if(!picks.length)return;
	var score=document.querySelector('[data-demo-score]');
	var result=document.querySelector('[data-demo-result]');
	var selected=picks.slice(0,2);
	function render(){
		var total=selected.reduce(function(sum,pick){return sum+Math.max(1,100-(parseInt(pick.getAttribute('data-age'),10)||0));},0);
		picks.forEach(function(pick){var active=selected.indexOf(pick)!==-1;pick.classList.toggle('is-selected',active);pick.setAttribute('aria-pressed',active?'true':'false');pick.querySelector('.ob-campaign__pick-mark').textContent=active?'✓':'+';});
		if(score)score.textContent=String(total);
		if(result)result.textContent=selected.length+' fictional pick'+(selected.length===1?'':'s')+' selected';
	}
	picks.forEach(function(button){button.addEventListener('click',function(){var index=selected.indexOf(button);if(index!==-1){selected.splice(index,1);}else{selected.push(button);}render();});});
	var random=document.querySelector('[data-demo-random]');
	if(random)random.addEventListener('click',function(){var available=picks.filter(function(pick){return selected.indexOf(pick)===-1;});if(available.length)selected.push(available[Math.floor(Math.random()*available.length)]);render();});
	render();
})();
