import React from 'react';
import { createRoot } from 'react-dom/client';
import CourseRegistrationApp from './components/CourseRegistrationApp';

// Wait for DOM to be loaded
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('react-course-registration');
  
  // Only initialize React if the container exists
  if (container) {
    // Get data passed from PHP
    const studentId = container.dataset.studentId || '';
    const semester = container.dataset.semester || '';
    const year = container.dataset.year || '';
    const programCode = container.dataset.programCode || '';
    
    // Create courses array from JSON if available
    let courses = [];
    try {
      courses = JSON.parse(container.dataset.courses || '[]');
    } catch (e) {
      console.error('Failed to parse courses:', e);
    }
    
    // Get preselected courses
    let preselectedCourses = [];
    try {
      preselectedCourses = JSON.parse(container.dataset.preselected || '[]');
    } catch (e) {
      console.error('Failed to parse preselected courses:', e);
    }
    
    // Detect CSRF token if available
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    // Keep session alive every 4 minutes to prevent timeouts
    const keepAliveInterval = setInterval(() => {
      fetch('api_get_eligibility.php?keepAlive=1', { 
        credentials: 'same-origin',
        cache: 'no-store'
      }).catch(e => console.warn('Session keep-alive ping failed:', e));
    }, 240000); // 4 minutes
    
    const root = createRoot(container);
    root.render(
      <CourseRegistrationApp 
        studentId={studentId}
        semester={semester}
        year={year}
        programCode={programCode}
        availableCourses={courses}
        preselectedCourses={preselectedCourses}
        csrfToken={csrfToken}
        onUnmount={() => clearInterval(keepAliveInterval)}
      />
    );
  }
});
