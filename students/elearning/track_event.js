// Lightweight client logger for analytics events
(function(){
  const portalRoot = (window.WUC_PORTAL_ROOT || '/wucportal/').replace(/\/?$/, '/');

  async function track(event){
    try {
      await fetch(portalRoot + 'elearning/api/events_log.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify(event)
      });
    } catch (e) {}
  }
  window.ElearnTrack = {
    contentView: function(course_code, module_id, content_id){
      track({event_type:'content_view', course_code, module_id, content_id});
    },
    forumPost: function(course_code, thread_id){
      track({event_type:'forum_post', course_code, metadata:{thread_id}});
    },
    videoProgress: function(course_code, content_id, seconds){
      track({event_type:'video_progress', course_code, content_id, metadata:{seconds}});
    },
    quizStart: function(course_code, quiz_id){
      track({event_type:'quiz_start', course_code, metadata:{quiz_id}});
    },
    quizSubmit: function(course_code, quiz_id, score){
      track({event_type:'quiz_submit', course_code, metadata:{quiz_id, score}});
    },
    assignmentView: function(course_code, assignment_id){
      track({event_type:'assignment_view', course_code, metadata:{assignment_id}});
    }
  };
})();


