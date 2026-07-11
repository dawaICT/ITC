import React, { useMemo } from 'react';
import Select from 'react-select';

const CourseSelector = ({ 
  availableCourses, 
  selectedCourses, 
  onChange,
  mandatoryCourses = []
}) => {
  // Transform courses into options format for react-select
  const courseOptions = useMemo(() => {
    return availableCourses.map(course => ({
      value: course.course_code,
      label: `${course.course_code} ${course.course_name || ''}`,
      isMandatory: mandatoryCourses.includes(course.course_code)
    }));
  }, [availableCourses, mandatoryCourses]);
  
  // Get the currently selected options
  const selectedOptions = useMemo(() => {
    return courseOptions.filter(option => 
      selectedCourses.includes(option.value)
    );
  }, [courseOptions, selectedCourses]);
  
  // Custom styles for react-select
  const customStyles = {
    option: (provided, state) => ({
      ...provided,
      backgroundColor: state.isSelected ? '#6f42c1' : 
                       state.isFocused ? '#f8f9fa' : 'white',
      color: state.isSelected ? 'white' : 
             (state.data.isMandatory ? '#dc3545' : '#2c3e50'),
      fontWeight: state.data.isMandatory ? 'bold' : 'normal',
    }),
    multiValue: (provided, state) => ({
      ...provided,
      backgroundColor: state.data.isMandatory ? '#ffecec' : '#e9ecef',
    }),
    multiValueLabel: (provided, state) => ({
      ...provided,
      color: state.data.isMandatory ? '#dc3545' : '#2c3e50',
      fontWeight: state.data.isMandatory ? 'bold' : 'normal',
    })
  };

  return (
    <div className="form-group mb-4">
      <label htmlFor="course-selector">Select Courses:</label>
      <Select
        id="course-selector"
        isMulti
        options={courseOptions}
        value={selectedOptions}
        onChange={onChange}
        styles={customStyles}
        className="course-multi-select"
        placeholder="Select courses to register..."
        closeMenuOnSelect={false}
        isClearable={false}
        isSearchable={true}
        hideSelectedOptions={false}
      />
      
      {mandatoryCourses.length > 0 && (
        <small className="text-danger">
          Note: Courses marked in red are mandatory and cannot be deselected.
        </small>
      )}
    </div>
  );
};

export default CourseSelector;
