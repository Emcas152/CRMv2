import { Component } from '@angular/core';
import { getStyle } from '@coreui/utils';
import { ChartData } from 'chart.js';
import { ChartjsComponent } from '@coreui/angular-chartjs';
import { CardBodyComponent, CardComponent, CardHeaderComponent, ColComponent, RowComponent } from '@coreui/angular';
import { DocsCalloutComponent } from '@docs-components/public-api';

@Component({
  selector: 'app-charts',
  templateUrl: './charts.component.html',
  imports: [RowComponent, ColComponent, DocsCalloutComponent, CardComponent, CardHeaderComponent, CardBodyComponent, ChartjsComponent]
})
export class ChartsComponent {

  options = {
    maintainAspectRatio: false
  };

  months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  chartBarData: ChartData = {
    labels: [...this.months].slice(0, 7),
    datasets: [
      {
        label: 'GitHub Commits',
        backgroundColor: getStyle('--cui-danger') || '#f87979',
        data: [40, 20, 12, 39, 17, 42, 79]
      }
    ]
  };

  // chartBarOptions = {
  //   maintainAspectRatio: false,
  // };

  chartLineData: ChartData = {
    labels: [...this.months].slice(0, 7),
    datasets: [
      {
        label: 'My First dataset',
        backgroundColor: 'rgba(220, 220, 220, 0.2)',
        borderColor: 'rgba(220, 220, 220, 1)',
        pointBackgroundColor: 'rgba(220, 220, 220, 1)',
        pointBorderColor: getStyle('--cui-body-bg') || '#fff',
        data: [this.randomData, this.randomData, this.randomData, this.randomData, this.randomData, this.randomData, this.randomData]
      },
      {
        label: 'My Second dataset',
        backgroundColor: 'rgba(151, 187, 205, 0.2)',
        borderColor: 'rgba(151, 187, 205, 1)',
        pointBackgroundColor: 'rgba(151, 187, 205, 1)',
        pointBorderColor: getStyle('--cui-body-bg') || '#fff',
        data: [this.randomData, this.randomData, this.randomData, this.randomData, this.randomData, this.randomData, this.randomData]
      }
    ]
  };

  chartLineOptions = {
    maintainAspectRatio: false
  };

  chartDoughnutData: ChartData = {
    labels: ['VueJs', 'EmberJs', 'ReactJs', 'Angular'],
    datasets: [
      {
        backgroundColor: [getStyle('--cui-success') || '#41B883', getStyle('--cui-danger') || '#E46651', getStyle('--cui-info') || '#00D8FF', getStyle('--cui-warning') || '#DD1B16'],
        data: [40, 20, 80, 10]
      }
    ]
  };

  // chartDoughnutOptions = {
  //   aspectRatio: 1,
  //   responsive: true,
  //   maintainAspectRatio: false,
  //   radius: '100%'
  // };

  chartPieData: ChartData = {
    labels: ['Red', 'Green', 'Yellow'],
    datasets: [
      {
        data: [300, 50, 100],
        backgroundColor: [getStyle('--cui-danger') || '#FF6384', getStyle('--cui-info') || '#36A2EB', getStyle('--cui-warning') || '#FFCE56'],
        hoverBackgroundColor: [getStyle('--cui-danger') || '#FF6384', getStyle('--cui-info') || '#36A2EB', getStyle('--cui-warning') || '#FFCE56']
      }
    ]
  };

  // chartPieOptions = {
  //   aspectRatio: 1,
  //   responsive: true,
  //   maintainAspectRatio: false,
  //   radius: '100%'
  // };

  chartPolarAreaData: ChartData = {
    labels: ['Red', 'Green', 'Yellow', 'Grey', 'Blue'],
    datasets: [
      {
        data: [11, 16, 7, 3, 14],
        backgroundColor: [getStyle('--cui-danger') || '#FF6384', getStyle('--cui-success') || '#4BC0C0', getStyle('--cui-warning') || '#FFCE56', getStyle('--cui-body-bg') || '#E7E9ED', getStyle('--cui-info') || '#36A2EB']
      }
    ]
  };

  chartRadarData: ChartData = {
    labels: ['Eating', 'Drinking', 'Sleeping', 'Designing', 'Coding', 'Cycling', 'Running'],
    datasets: [
      {
        label: '2020',
        backgroundColor: 'rgba(179,181,198,0.2)',
        borderColor: 'rgba(179,181,198,1)',
        pointBackgroundColor: 'rgba(179,181,198,1)',
        pointBorderColor: getStyle('--cui-body-bg') || '#fff',
        pointHoverBackgroundColor: getStyle('--cui-body-bg') || '#fff',
        pointHoverBorderColor: 'rgba(179,181,198,1)',
        data: [65, 59, 90, 81, 56, 55, 40]
      },
      {
        label: '2021',
        backgroundColor: 'rgba(255,99,132,0.2)',
        borderColor: 'rgba(255,99,132,1)',
        pointBackgroundColor: 'rgba(255,99,132,1)',
        pointBorderColor: getStyle('--cui-body-bg') || '#fff',
        pointHoverBackgroundColor: getStyle('--cui-body-bg') || '#fff',
        pointHoverBorderColor: 'rgba(255,99,132,1)',
        data: [this.randomData, this.randomData, this.randomData, this.randomData, this.randomData, this.randomData, this.randomData]
      }
    ]
  };

  // chartRadarOptions = {
  //   aspectRatio: 1.5,
  //   responsive: true,
  //   maintainAspectRatio: false,
  // };

  get randomData() {
    return Math.round(Math.random() * 100);
  }

}
