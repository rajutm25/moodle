# Assignment Feedback #
 
A Moodle AI Placement plugin that provides AI-powered assignment analysis, grading, and content originality checks directly within student-submitted assignment content, accessible through a convenient context menu.

 ## Features
 
 - Context menu for selected text in student submissions.
 - Three AI-powered analysis options:
   - Feedback: Provides detailed, constructive feedback on the submission.
   - Grade: Assigns a numeric or letter grade out of 100 along with explanations for strengths and areas of improvement.
   - AI Content Detector: Evaluates the submission for originality and potential AI-generated content.
 - Role-based access control for each feature
 - Responsive tooltip-style results display
 - Dynamic prompts for grading and AI detection based on the selected text or full submission.


# Plugin Dependency on Moodle AI provider configuration


## Installing manually ##

The plugin can be also installed by putting the contents of this directory to

    {your/moodle/dirroot}/ai/placement/assignfeedback

Afterwards, log in to your Moodle site as an admin and go to _Site administration >
Notifications_ to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## License ##

2025 Raju Thummoji  <rajutummoji@gmail.com>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program.  If not, see <https://www.gnu.org/licenses/>.
