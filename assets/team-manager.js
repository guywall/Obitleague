(function(){
	'use strict';
	var root=document.querySelector('[data-ob-main-team]');
	if(!root)return;
	var api=root.dataset.rest,nonce=root.dataset.nonce,verified=root.dataset.verified==='1';

	function request(path,method,body){
		return fetch(api+path,{method:method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:body?JSON.stringify(body):undefined})
			.then(function(response){return response.json().then(function(data){if(!response.ok)throw new Error(data.message||'Request failed.');return data;});});
	}
	function disambiguation(person,source){
		var bits=[];
		if(person.age!==null&&person.age!==undefined)bits.push('age '+person.age);
		else if(person.birth)bits.push('born '+person.birth);
		if(person.occupations&&person.occupations.length)bits.push(person.occupations.join(', '));
		else if(person.role)bits.push(person.role);
		if(source==='wikidata'&&person.qid)bits.push(person.qid);
		return bits.join(' · ');
	}
	function identityLink(person,source){
		var enwiki=person.enwiki||'';
		if(enwiki)return {href:'https://en.wikipedia.org/wiki/'+encodeURIComponent(enwiki.replace(/ /g,'_')),label:'View on Wikipedia'};
		if(source==='wikidata'&&person.url)return {href:person.url,label:'View on Wikidata'};
		return null;
	}
	function createResult(person,source,onAdd){
		var li=document.createElement('li'),button=document.createElement('button');
		var name=document.createElement('span'),meta=document.createElement('small');
		li.className='ob-pick-result-item';
		button.type='button';button.className='ob-pick-result';
		name.className='ob-pick-result__name';name.textContent=person.name;
		meta.className='ob-pick-result__meta';meta.textContent=disambiguation(person,source);
		button.append(name,meta);
		button.addEventListener('click',function(){onAdd(person,button);});
		li.appendChild(button);
		var ref=identityLink(person,source);
		if(ref){
			var link=document.createElement('a');
			link.className='ob-pick-result__wiki';link.href=ref.href;link.target='_blank';link.rel='noopener noreferrer';
			link.textContent=ref.label;
			li.appendChild(link);
		}
		return li;
	}
	function mountEditor(editor,kind,data,canEdit){
		var picks=(data.pick_details||[]).slice(),version=data.expected_version||1;
		var search=editor.querySelector('[data-'+kind+'-search]'),results=editor.querySelector('[data-'+kind+'-results]');
		var list=editor.querySelector('[data-'+kind+'-picks]'),count=editor.querySelector('[data-'+kind+'-count]');
		var name=editor.querySelector('[data-'+kind+'-name]'),message=editor.querySelector('[data-'+kind+'-message]');
		var timer=0,requestNumber=0;
		if(name)name.value=data.team_name||'';
		function render(){
			list.replaceChildren();
			picks.forEach(function(person,index){
				var li=document.createElement('li'),label=document.createElement('span'),detail=document.createElement('small'),remove=document.createElement('button');
				label.textContent=(index+1)+'. '+(person.name||person.uuid);detail.textContent=disambiguation(person,'local');
				remove.type='button';remove.textContent='Remove';remove.disabled=!canEdit;remove.setAttribute('aria-label','Remove '+(person.name||'pick'));
				remove.addEventListener('click',function(){picks.splice(index,1);render();});li.append(label,detail,remove);list.appendChild(li);
			});
			if(count)count.textContent='('+picks.length+'/10)';
		}
		function add(person){
			if(!canEdit){return false;}
			if(picks.length>=10){message.textContent='Your team already has ten picks.';return false;}
			if(!person.uuid||picks.some(function(pick){return pick.uuid===person.uuid;})){message.textContent='That person is already on your team.';return false;}
			picks.push(person);render();results.replaceChildren();search.value='';message.textContent='';return true;
		}
		function showPeople(people,source){
			results.replaceChildren();
			if(!people.length){var empty=document.createElement('li');empty.textContent=source==='local'?'No eligible catalogue matches. Search Wikidata for a living human instead.':'No eligible living human matches found.';results.appendChild(empty);return;}
			people.forEach(function(person){
				results.appendChild(createResult(person,source,function(candidate,button){
					if(source==='local'){add(candidate);return;}
					button.disabled=true;button.firstChild.textContent='Adding '+candidate.name+' to the site…';
					request('/wikidata/people','POST',{qid:candidate.qid}).then(function(added){
						add({uuid:added.uuid,name:added.name,age:candidate.age,birth:candidate.birth,occupations:candidate.occupations,role:(candidate.occupations||[]).join(', ')});
					}).catch(function(error){message.textContent=error.message;button.disabled=false;button.firstChild.textContent=candidate.name;});
				}));
			});
		}
		function showWikidataOption(term){
			var li=document.createElement('li'),button=document.createElement('button');button.type='button';button.className='ob-wikidata-search';button.textContent='No local match? Search Wikidata for living humans';
			button.addEventListener('click',function(){button.disabled=true;button.textContent='Searching Wikidata…';
				request('/wikidata/people?search='+encodeURIComponent(term),'GET').then(function(people){
					if(!people.length){showPeople([], 'wikidata');return;}
					message.textContent='Choose a Wikidata result to add the person to the site and your team.';showPeople(people,'wikidata');
				}).catch(function(error){message.textContent=error.message;button.disabled=false;button.textContent='Retry Wikidata search';});
			});li.appendChild(button);results.appendChild(li);
		}
		search.disabled=!canEdit;
		search.addEventListener('input',function(){
			clearTimeout(timer);var term=search.value.trim(),sequence=++requestNumber;
			if(term.length<2){results.replaceChildren();return;}
			timer=setTimeout(function(){request('/people?search='+encodeURIComponent(term)+'&per_page=20','GET').then(function(people){
				if(sequence!==requestNumber)return;
				people=people.filter(function(person){return person.selectable&&!picks.some(function(pick){return pick.uuid===person.uuid;});});
				showPeople(people,'local');if(!people.length)showWikidataOption(term);
			}).catch(function(error){if(sequence===requestNumber)message.textContent=error.message;});},250);
		});
		editor.querySelector('[data-'+kind+'-save]').disabled=!canEdit;
		editor.querySelector('[data-'+kind+'-save]').addEventListener('click',function(){
			if(picks.length!==10){message.textContent='Choose exactly ten eligible people before saving.';return;}
			message.textContent='Saving…';var path=kind==='main'?'/main-entry':'/entries/'+data.entry_id;
			var body={picks:picks.map(function(person){return person.uuid;}),expected_version:version};if(name)body.team_name=name.value;
			var button=editor.querySelector('[data-'+kind+'-save]');button.disabled=true;
			request(path,'PUT',body).then(function(saved){version=saved.expected_version;data.state=saved.state||data.state;message.textContent='Team saved.';}).catch(function(error){message.textContent=error.message;}).finally(function(){button.disabled=!canEdit;});
		});
		var submit=editor.querySelector('[data-'+kind+'-submit]');
		if(submit){submit.hidden=!canEdit||data.state==='submitted';submit.disabled=!canEdit;submit.addEventListener('click',function(){
			if(picks.length!==10){message.textContent='Choose exactly ten eligible people before submitting.';return;}
			if(!window.confirm('Submit this team? You can still amend it until the next season starts.'))return;
			message.textContent='Submitting…';var path=kind==='main'?'/main-entry/submit':'/entries/'+data.entry_id+'/submit';
			submit.disabled=true;
			request(path,'POST',{picks:picks.map(function(person){return person.uuid;})}).then(function(){data.state='submitted';submit.hidden=true;message.textContent='Team submitted. You can continue to amend it until the next season starts.';}).catch(function(error){message.textContent=error.message;}).finally(function(){submit.disabled=!canEdit||data.state==='submitted';});
		});}
		render();
	}

	var mainEditor=root.querySelector('[data-main-editor]'),mainMessage=root.querySelector('[data-main-message]');
	request('/main-entry','GET').then(function(data){
		var canEdit=!!data.can_edit&&verified;
		if(data.pick_details&&data.pick_details.length)mainEditor.hidden=false;
		else if(window.location.hash==='#build-team'&&canEdit)mainEditor.hidden=false;
		var status=root.querySelector('[data-main-status]');if(status)status.textContent=data.state;
		var trigger=root.querySelector('[data-main-edit]');if(trigger)trigger.addEventListener('click',function(){mainEditor.hidden=false;});
		mountEditor(mainEditor,'main',data,canEdit);
	}).catch(function(error){mainMessage.textContent=error.message;});

	Array.prototype.forEach.call(document.querySelectorAll('[data-side-team]'),function(section){
		var trigger=section.querySelector('[data-side-edit]'),editor=section.querySelector('[data-side-editor]');
		if(!trigger||!editor)return;
		var message=editor.querySelector('[data-side-message]');
		trigger.addEventListener('click',function(){editor.hidden=false;if(editor.dataset.loaded==='1')return;editor.dataset.loaded='1';
			request('/entries/'+section.dataset.entryId,'GET').then(function(data){mountEditor(editor,'side',data,!!data.can_edit&&verified);}).catch(function(error){editor.dataset.loaded='';message.textContent=error.message;});
		});
	});
})();
